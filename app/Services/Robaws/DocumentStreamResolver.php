<?php

namespace App\Services\Robaws;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Document file resolution utilities
 */
class DocumentStreamResolver
{
    /**
     * Attempts to resolve a Document file into a readable stream and metadata.
     * - Honors $document->storage_disk if set (fallback to config('filesystems.default'))
     * - Normalizes paths that were saved with/without disk root prefix
     * - Works for local and S3 (DO Spaces) via readStream/mimeType/size
     */
    public static function openDocumentStream(Document $document, LoggerInterface $logger): array
    {
        $diskName = $document->storage_disk ?: (config('filesystems.cloud') ?: config('filesystems.default'));
        $disk = Storage::disk($diskName);

        // Prefer $document->file_path, fallback to $document->filepath, finally to $document->path
        $rawPath = $document->file_path ?? $document->filepath ?? $document->path ?? null;
        if (!$rawPath) {
            throw new \RuntimeException("Document #{$document->id} has no file path");
        }

        // Normalize: strip leading slashes, remove disk root prefix if double-saved
        $path = ltrim($rawPath, '/');
        $root = rtrim((string)config("filesystems.disks.$diskName.root"), '/'); // null on S3
        if ($root) {
            $rootPrefix = trim($root, '/').'/';
            if (Str::startsWith($path, $rootPrefix)) {
                $path = Str::after($path, $rootPrefix);
            }
        }

        // Common prefixes that might have been double-saved
        $pathVariants = [
            $path,
            $rawPath,
            // Remove common prefixes
            str_replace('documents/', '', $path),
            str_replace('private/documents/', '', $path),
            'private/documents/' . str_replace('documents/', '', $path),
            // In case it's stored without prefix but disk expects it
            'documents/' . $path,
            'private/documents/' . $path,
        ];

        // Try each variant until we find the file
        $resolved = null;
        foreach (array_unique($pathVariants) as $variant) {
            if ($disk->exists($variant)) {
                $resolved = $variant;
                break;
            }
        }

        if (!$resolved) {
            $logger->error('File not found on disk', [
                'doc_id' => $document->id,
                'disk' => $diskName,
                'tried' => $pathVariants,
            ]);
            throw new \RuntimeException("File not found for document #{$document->id} on disk [$diskName]. Tried: " . implode(', ', $pathVariants));
        }

        // Streams work for both local and S3
        $stream = $disk->readStream($resolved);
        if (!is_resource($stream)) {
            throw new \RuntimeException("Unable to open stream for #{$document->id} at [$resolved]");
        }

        return [
            'disk'      => $disk,
            'disk_name' => $diskName,
            'path'      => $resolved,
            'stream'    => $stream,
            'filename'  => $document->original_filename ?: basename($resolved),
            'mime'      => $disk->mimeType($resolved) ?: 'application/octet-stream',
            'size'      => $disk->size($resolved) ?: null,
        ];
    }

    /**
     * Compute SHA256 from disk without loading whole file into memory
     */
    public static function computeSha256FromDisk($disk, string $path): string
    {
        $ctx = hash_init('sha256');
        $stream = $disk->readStream($path);
        if (!is_resource($stream)) {
            throw new \RuntimeException("Cannot hash: failed to open stream for [$path]");
        }
        try {
            while (!feof($stream)) {
                $buf = fread($stream, 1024 * 1024);
                if ($buf === false) break;
                hash_update($ctx, $buf);
            }
        } finally {
            fclose($stream);
        }
        return hash_final($ctx);
    }
}
