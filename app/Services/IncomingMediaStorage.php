<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class IncomingMediaStorage
{
    public function diskName(): string
    {
        return (string) config('services.evolution.incoming_media_disk', 'private_media');
    }

    public function store(string $contents, string $extension): string
    {
        $extension = strtolower($extension);
        if (!preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
            $extension = 'bin';
        }

        $path = 'media/inbound/' . now()->format('Y/m') . '/' . Str::uuid() . '.' . $extension;
        if (!Storage::disk($this->diskName())->put($path, $contents)) {
            throw new \RuntimeException('Could not store inbound media.');
        }

        return $path;
    }

    public function absolutePath(string $relativePath, ?string $diskName = null): ?string
    {
        $diskName ??= $this->diskName();
        $decoded = rawurldecode($relativePath);
        if ($decoded === '' || str_contains($decoded, "\0") || str_contains($decoded, '\\')
            || preg_match('#^https?://#i', $decoded)) {
            return null;
        }

        $segments = explode('/', ltrim($decoded, '/'));
        $normalizedPath = implode('/', $segments);
        $allowedPrefix = $diskName === 'public' ? 'whatsapp/' : 'media/inbound/';
        if (in_array('..', $segments, true) || in_array('.', $segments, true)
            || !str_starts_with($normalizedPath, $allowedPrefix)) {
            return null;
        }

        $disk = Storage::disk($diskName);
        $candidate = $disk->path($normalizedPath);
        $resolved = realpath($candidate);
        $root = realpath($disk->path(''));
        if (!$resolved || !$root || !is_file($resolved)) {
            return null;
        }

        return str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            ? $resolved
            : null;
    }
}
