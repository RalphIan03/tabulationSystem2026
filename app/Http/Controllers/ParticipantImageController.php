<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class ParticipantImageController extends Controller
{
    public function show(string $image): Response
    {
        $storedImage = DB::table('participant_images')->find($image);

        abort_if($storedImage === null, 404);

        $contents = base64_decode($storedImage->contents, true);

        abort_if($contents === false, 404);

        return response($contents)
            ->header('Content-Type', $storedImage->mime_type)
            ->header('Content-Disposition', 'inline')
            ->header('Cache-Control', 'public, max-age=31536000, immutable')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
