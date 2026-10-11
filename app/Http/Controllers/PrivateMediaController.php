<?php

namespace App\Http\Controllers;

use App\Services\IncomingMediaStorage;
use Illuminate\Support\Facades\Storage;

class PrivateMediaController extends Controller
{
    public function showTts(string $mediaPath, IncomingMediaStorage $storage)
    {
        $disk = $storage->diskName();
        if ($storage->absolutePath($mediaPath, $disk) === null || !str_ends_with(strtolower($mediaPath), '.mp3')) {
            return response()->json(['message' => 'Media not found.'], 404);
        }

        return Storage::disk($disk)->response($mediaPath, null, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
