<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

final class DocumentStorageConfig
{
    /**
     * Get the appropriate storage disk for the current environment
     */
    public static function getStorageDisk(): string
    {
        if (app()->environment('local')) {
            return config('filesystems.documents_config.local_fallback', 'local');
        }
        
        return config('filesystems.documents_config.default_disk', config('filesystems.default', 'local'));
    }
    
    /**
     * Get the storage path for documents
     */
    public static function getDocumentPath(string $filename): string
    {
        return ltrim("documents/{$filename}", '/');
    }
    
    /**
     * Log storage disk selection for debugging
     */
    public static function logStorageSelection(string $context, string $disk): void
    {
        Log::info('Document storage disk selected', [
            'context' => $context,
            'environment' => app()->environment(),
            'selected_disk' => $disk,
            'config_default' => config('filesystems.documents_config.default_disk'),
            'config_fallback' => config('filesystems.documents_config.local_fallback'),
        ]);
    }
}
