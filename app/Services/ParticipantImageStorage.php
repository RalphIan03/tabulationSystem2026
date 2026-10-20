<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ParticipantImageStorage
{
    private const MAX_STORED_BYTES = 2048 * 1024;

    private const DATABASE_PREFIX = 'database:';

    public function store(UploadedFile $file, Event $event): string
    {
        $directory = "participants/{$event->getKey()}";

        if ($file->getSize() <= self::MAX_STORED_BYTES) {
            try {
                $path = Storage::disk('public')->putFile($directory, $file);

                if (is_string($path)) {
                    return $path;
                }
            } catch (Throwable) {
                // A read-only local disk is expected on some deployments.
            }

            $contents = file_get_contents($file->getRealPath());

            if ($contents === false) {
                throw ValidationException::withMessages([
                    'participant_image' => 'The profile picture could not be read. Please try another image.',
                ]);
            }

            $mimeType = $file->getMimeType();

            if (! in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                throw ValidationException::withMessages([
                    'participant_image' => 'The profile picture must be a JPG, PNG, or WebP image.',
                ]);
            }

            return $this->storeInDatabase($event, $contents, $mimeType);
        }

        [$contents, $extension] = $this->compress($file);
        $path = $directory.'/'.Str::random(40).'.'.$extension;

        try {
            if (Storage::disk('public')->put($path, $contents)) {
                return $path;
            }
        } catch (Throwable) {
            // A read-only local disk is expected on some deployments.
        }

        return $this->storeInDatabase($event, $contents, $extension === 'webp' ? 'image/webp' : 'image/jpeg');
    }

    public function url(string $path): string
    {
        if (str_starts_with($path, self::DATABASE_PREFIX)) {
            return route('participant-images.show', substr($path, strlen(self::DATABASE_PREFIX)), false);
        }

        return Storage::disk('public')->url($path);
    }

    public function delete(string $path): void
    {
        if (str_starts_with($path, self::DATABASE_PREFIX)) {
            DB::table('participant_images')->where('id', substr($path, strlen(self::DATABASE_PREFIX)))->delete();

            return;
        }

        try {
            Storage::disk('public')->delete($path);
        } catch (Throwable) {
            // The legacy file may be on a read-only deployment filesystem.
        }
    }

    private function storeInDatabase(Event $event, string $contents, string $mimeType): string
    {
        $id = (string) Str::uuid();

        DB::table('participant_images')->insert([
            'id' => $id,
            'event_id' => $event->getKey(),
            'mime_type' => $mimeType,
            'contents' => base64_encode($contents),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return self::DATABASE_PREFIX.$id;
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
