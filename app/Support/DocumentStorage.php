<?php

namespace App\Support;

use App\Models\Document;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DocumentStorage
{
    /**
     * Try to open a read stream for the document.
     * In local env, if the configured disk fails (e.g. 'spaces'), try local.
     */
    public static function openStream(Document $doc)
    {
        $disk = $doc->storage_disk ?: config('filesystems.default', 'local');
        $path = $doc->file_path;

        // 1) Try the configured disk
        try {
            $stream = Storage::disk($disk)->readStream($path);
            if ($stream !== false) {
                Log::debug('Document read from primary disk', [
                    'document_id' => $doc->id,
                    'disk' => $disk,
                    'path' => $path
                ]);
                return $stream;
            }
        } catch (\Throwable $e) {
            Log::warning('Primary disk read failed', [
                'document_id' => $doc->id,
                'disk' => $disk, 
                'path' => $path, 
                'error' => $e->getMessage()
            ]);
        }

        // 2) Local-only fallback
        if (App::environment('local')) {
            Log::info('Attempting local fallback for document', [
                'document_id' => $doc->id,
                'original_disk' => $disk,
                'original_path' => $path
            ]);

            try {
                // Same relative path on local disk
                if (Storage::disk('local')->exists($path)) {
                    Log::info('Found document using same path on local disk', [
                        'document_id' => $doc->id,
                        'path' => $path
                    ]);
                    return Storage::disk('local')->readStream($path);
                }

                // Common alternative roots for dev
                $alts = [
                    'private/documents/' . basename($path),
                    'documents/' . basename($path),
                    'uploads/documents/' . $doc->filename,
                    'documents/' . $doc->filename,
                    basename($path),
                    $doc->filename
                ];

                foreach ($alts as $alt) {
                    if (Storage::disk('local')->exists($alt)) {
                        Log::info('Using local fallback file', [
                            'document_id' => $doc->id,
                            'fallback_path' => $alt,
                            'original_path' => $path
                        ]);
                        return Storage::disk('local')->readStream($alt);
                    }
                }

                // Try direct file system access as last resort
                $workspacePaths = [
                    base_path($doc->filename),
                    base_path('storage/app/private/documents/' . basename($path)),
                    base_path('storage/app/documents/' . basename($path)),
                ];

                foreach ($workspacePaths as $workspacePath) {
                    if (file_exists($workspacePath)) {
                        Log::info('Found document in workspace', [
                            'document_id' => $doc->id,
                            'workspace_path' => $workspacePath
                        ]);
                        return fopen($workspacePath, 'r');
                    }
                }

            } catch (\Throwable $e) {
                Log::warning('Local fallback read failed', [
                    'document_id' => $doc->id,
                    'path' => $path, 
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::error('All document read strategies failed', [
            'document_id' => $doc->id,
            'disk' => $disk,
            'path' => $path,
            'environment' => App::environment(),
            'guidance' => self::getEnvironmentGuidance($doc)
        ]);

        return false;
    }

    /**
     * Get document content as string with fallback handling
     */
    public static function getContent(Document $doc): ?string
    {
        $stream = self::openStream($doc);
        if (!$stream) {
            return null;
        }

        $bytes = stream_get_contents($stream);
        fclose($stream);

        return $bytes !== false ? $bytes : null;
    }

    /**
     * Optional: mirror bytes to local and update the document row.
     * Call after successfully reading from a remote disk in dev.
     */
    public static function mirrorToLocal(Document $doc, string $bytes): bool
    {
        // Disable mirroring to prevent storage_disk override
        return false;
        
        if (!App::environment('local')) {
            return false;
        }

        try {
            $ok = Storage::disk('local')->put($doc->file_path, $bytes, ['visibility' => 'private']);
            if ($ok) {
                $doc->forceFill(['storage_disk' => 'local'])->save();
                Log::info('Mirrored document to local', [
                    'document_id' => $doc->id, 
                    'path' => $doc->file_path
                ]);
            }
            return $ok;
        } catch (\Throwable $e) {
            Log::error('Failed to mirror document to local', [
                'document_id' => $doc->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if document is accessible
     */
    public static function exists(Document $doc): bool
    {
        $disk = $doc->storage_disk ?: config('filesystems.default', 'local');
        $path = $doc->file_path;

        try {
            return Storage::disk($disk)->exists($path);
        } catch (\Throwable $e) {
            // In local env, try fallback locations
            if (App::environment('local')) {
                return self::openStream($doc) !== false;
            }
            return false;
        }
    }

    /**
     * Get environment-specific guidance for storage issues
     */
    private static function getEnvironmentGuidance(Document $doc): string
    {
        $env = App::environment();
        $originalDisk = $doc->storage_disk;

        if ($env === 'local' && $originalDisk === 'spaces') {
            return 'Document stored on DigitalOcean Spaces but running locally. Solutions: 1) Use documents:mirror command, 2) Configure DO Spaces credentials, 3) Copy file to local storage manually';
        }

        if ($env === 'production' && $originalDisk === 'local') {
            return 'Document stored locally but running in production. Consider migrating to cloud storage.';
        }

        return 'Cross-environment storage mismatch detected. Check storage configuration and file locations.';
    }
}
