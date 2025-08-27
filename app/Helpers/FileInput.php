<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class FileInput
{
    /**
     * Prepare file input for AI extractors based on storage configuration
     * 
     * @param string $path The file path in storage
     * @param string $mime The MIME type of the file
     * @return array Either ['url' => $url, 'mime' => $mime] or ['bytes' => $base64bytes, 'mime' => $mime]
     */
    public static function forExtractor(string $path, string $mime): array
    {
        $disk = Storage::disk(config('filesystems.default', 'local'));
        
        // For local development, always use bytes
        if (config('filesystems.default') === 'local' || config('app.env') === 'local') {
            Log::info('FileInput: Using bytes for local development', [
                'path' => $path,
                'mime' => $mime
            ]);
            
            if (!$disk->exists($path)) {
                throw new \Exception("File not found at path: {$path}");
            }
            
            $bytes = $disk->get($path);
            return [
                'bytes' => base64_encode($bytes), 
                'mime' => $mime
            ];
        }
        
        // Production: S3/Spaces → signed URL
        Log::info('FileInput: Using URL for production', [
            'path' => $path,
            'mime' => $mime
        ]);
        
        try {
            $url = $disk->temporaryUrl($path, now()->addMinutes(20));
        } catch (\Exception $e) {
            // Fallback to regular URL if temporary URLs not supported
            $url = $disk->url($path);
        }
        
        return [
            'url' => $url, 
            'mime' => $mime
        ];
    }
}