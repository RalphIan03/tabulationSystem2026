<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ParticipantImageStorage
{
    private const MAX_STORED_BYTES = 2048 * 1024;

    public function store(UploadedFile $file, Event $event): string
    {
        $directory = "participants/{$event->getKey()}";

        if ($file->getSize() <= self::MAX_STORED_BYTES) {
            $path = $file->store($directory, 'public');

            if ($path === false) {
                throw new RuntimeException('The participant profile picture could not be stored.');
            }

            return $path;
        }

        [$contents, $extension] = $this->compress($file);
        $path = $directory.'/'.Str::random(40).'.'.$extension;

        if (! Storage::disk('public')->put($path, $contents)) {
            throw new RuntimeException('The participant profile picture could not be stored.');
        }

        return $path;
    }

    /** @return array{string, string} */
    private function compress(UploadedFile $file): array
    {
        if (! function_exists('imagecreatefromstring')) {
            throw ValidationException::withMessages([
                'participant_image' => 'Image compression is unavailable on this server. Please upload a picture under 2 MB.',
            ]);
        }

        $sourceBytes = file_get_contents($file->getRealPath());
        $source = $sourceBytes === false ? false : @imagecreatefromstring($sourceBytes);

        if (! $source instanceof GdImage) {
            throw ValidationException::withMessages([
                'participant_image' => 'The profile picture could not be processed. Please try another image.',
            ]);
        }

        $useWebp = function_exists('imagewebp') && (imagetypes() & IMG_WEBP) !== 0;
        $extension = $useWebp ? 'webp' : 'jpg';
        $longestSide = max(imagesx($source), imagesy($source));
        $targetLongestSide = min($longestSide, 2000);

        try {
            while (true) {
                $scale = $targetLongestSide / $longestSide;
                $width = max(1, (int) round(imagesx($source) * $scale));
                $height = max(1, (int) round(imagesy($source) * $scale));
                $resized = imagecreatetruecolor($width, $height);

                if (! $resized instanceof GdImage) {
                    break;
                }

                try {
                    if ($useWebp) {
                        imagealphablending($resized, false);
                        imagesavealpha($resized, true);
                        imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
                    } else {
                        imagefill($resized, 0, 0, imagecolorallocate($resized, 255, 255, 255));
                    }

                    imagecopyresampled($resized, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

                    foreach ([85, 75, 65, 50, 35] as $quality) {
                        $contents = $this->encode($resized, $useWebp, $quality);

                        if ($contents !== null && strlen($contents) <= self::MAX_STORED_BYTES) {
                            return [$contents, $extension];
                        }
                    }
                } finally {
                    imagedestroy($resized);
                }

                if ($targetLongestSide <= 320) {
                    break;
                }

                $targetLongestSide = max(320, (int) floor($targetLongestSide * 0.75));
            }
        } finally {
            imagedestroy($source);
        }

        throw ValidationException::withMessages([
            'participant_image' => 'The profile picture could not be compressed to 2 MB. Please try another image.',
        ]);
    }

    private function encode(GdImage $image, bool $useWebp, int $quality): ?string
    {
        ob_start();

        try {
            $encoded = $useWebp
                ? imagewebp($image, null, $quality)
                : imagejpeg($image, null, $quality);
            $contents = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        return $encoded && is_string($contents) && $contents !== '' ? $contents : null;
    }
}
