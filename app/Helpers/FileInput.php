<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Storage;

class FileInput
{
        /**
     * Prepare file input for AI extractor based on storage configuration.
     *
     * @param string $path The file path in storage
     * @param string $mime The MIME type of the file
     * @return array Either ['url' => $url] or ['bytes' => $base64bytes, 'mime' => $mime]
     */
    public static function forExtractor(string $path, string $mime): array
    {
        $disk = Storage::disk(config('filesystems.default'));

        // Production: S3/Spaces → signed URL (or public URL)
        if (config('filesystems.default') === 's3' && config('app.env') === 'production') {
            $url = $disk->temporaryUrl($path, now()->addMinutes(20));
            return ['url' => $url, 'mime' => $mime];
        }

        // Development or when S3 URL fails: read file as bytes
        try {
            // Check if file exists
            if (!$disk->exists($path)) {
                throw new \RuntimeException("File not found: {$path}");
            }
            
            $contents = $disk->get($path);
            if ($contents === false) {
                throw new \RuntimeException("Could not read file: {$path}");
            }
            
            $base64 = base64_encode($contents);
            return ['bytes' => $base64, 'mime' => $mime];
            
        } catch (\Exception $e) {
            // If we can't read the file directly, try the URL approach as fallback
            // But first check if we can generate a URL
            try {
                $url = $disk->temporaryUrl($path, now()->addMinutes(20));
                return ['url' => $url, 'mime' => $mime];
            } catch (\Exception $urlException) {
                throw new \RuntimeException(
                    "Cannot access file via bytes or URL. Bytes error: {$e->getMessage()}. URL error: {$urlException->getMessage()}"
                );
            }
        }
    }
}
