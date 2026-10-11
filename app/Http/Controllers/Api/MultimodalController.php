<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActiveBusiness;
use App\Services\ImageUnderstandingService;
use Illuminate\Http\Request;

class MultimodalController extends Controller
{
    public function testImage(Request $request, ActiveBusiness $businesses)
    {
        $business = $businesses->resolve($request, permission: 'read');
        abort_unless($business->media_processing_disclosed_at !== null, 403, 'Acknowledge the media processing disclosure before testing images.');
        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        if (!ImageUnderstandingService::isAvailable()) {
            return response()->json(['message' => 'Image analysis is not configured on this server.'], 503);
        }

        $image = $validated['image'];
        $extension = $image->guessExtension() ?: 'jpg';
        $relativePath = app(\App\Services\IncomingMediaStorage::class)->store(
            (string) file_get_contents($image->getRealPath()),
            $extension
        );
        $imagePath = app(\App\Services\IncomingMediaStorage::class)->absolutePath($relativePath);
        if (!$imagePath) {
            return response()->json(['message' => 'The uploaded image could not be stored safely.'], 422);
        }

        try {
            $result = ImageUnderstandingService::analyzeImage($imagePath);
        } finally {
            \Illuminate\Support\Facades\Storage::disk(app(\App\Services\IncomingMediaStorage::class)->diskName())
                ->delete($relativePath);
        }
        if (isset($result['error'])) {
            return response()->json(['message' => 'The configured image analysis provider could not process this image.'], 502);
        }

        return response()->json([
            'description' => $result['raw_analysis'] ?? '',
            'extracted_text' => $result['extracted_text'] ?? '',
            'product_matches' => $result['product_matches'] ?? [],
            'confidence' => $result['confidence'] ?? 0,
            'detected_intent' => $result['detected_intent'] ?? 'unknown',
        ]);
    }
}
