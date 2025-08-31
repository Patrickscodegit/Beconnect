<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

class Files
{
    /**
     * Attempts to open a read stream for a document path, trying multiple disks & normalized variants.
     * Returns: ['stream' => resource, 'path' => string, 'disk' => string, 'filename' => string, 'mime' => string]
     *
     * @throws \RuntimeException if not found anywhere
     */
    public static function openDocumentStream(string $inputPath, array $disksInOrder = ['documents','local','s3']): array
    {
        $candidates = self::candidatePaths($inputPath);

        foreach ($disksInOrder as $disk) {
            if (!self::diskExists($disk)) {
                continue;
            }
            foreach ($candidates as $candidate) {
                if (Storage::disk($disk)->exists($candidate)) {
                    $stream = Storage::disk($disk)->readStream($candidate);
                    if ($stream === false) {
                        continue;
                    }

                    $filename = basename($candidate);
                    $mime = self::guessMime($disk, $candidate, $filename);

                    // Ensure stream is at position 0
                    if (ftell($stream) !== 0) {
                        @rewind($stream);
                    }

                    return [
                        'stream'   => $stream,
                        'path'     => $candidate,
                        'disk'     => $disk,
                        'filename' => $filename,
                        'mime'     => $mime,
                    ];
                }
            }
        }

        // Final local filesystem fallback (symlinked/absolute)
        foreach ($candidates as $candidate) {
            $abs = storage_path('app/' . ltrim($candidate, '/'));
            if (is_file($abs)) {
                $stream = fopen($abs, 'rb');
                if ($stream === false) {
                    continue;
                }
                $filename = basename($abs);
                $mime = self::guessMimeFromFilename($filename);

                if (ftell($stream) !== 0) {
                    @rewind($stream);
                }

                return [
                    'stream'   => $stream,
                    'path'     => $abs,
                    'disk'     => 'filesystem',
                    'filename' => $filename,
                    'mime'     => $mime,
                ];
            }
        }

        throw new \RuntimeException("Document not found on any disk. Tried: ".implode(', ', $disksInOrder)." | Paths: ".implode(' | ', $candidates));
    }

    private static function candidatePaths(string $path): array
    {
        $p = trim($path);
        $p = Str::of($p)->replace('\\', '/')->__toString();

        $variants = [];

        // raw
        $variants[] = ltrim($p, '/');

        // strip leading 'documents/'
        $variants[] = ltrim(Str::of($p)->after('documents/')->__toString(), '/');

        // ensure prefixed with 'documents/'
        $stripped = ltrim($p, '/');
        if (!Str::startsWith($stripped, 'documents/')) {
            $variants[] = 'documents/'.$stripped;
        }

        // dedupe
        return array_values(array_unique(array_filter($variants)));
    }

    private static function diskExists(string $disk): bool
    {
        try {
            Storage::disk($disk);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function guessMime(string $disk, string $path, string $filename): string
    {
        // Try storage-provided
        try {
            $mime = Storage::disk($disk)->mimeType($path);
            if ($mime) return $mime;
        } catch (\Throwable $e) {}

        return self::guessMimeFromFilename($filename);
    }

    private static function guessMimeFromFilename(string $filename): string
    {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if (!$ext) return 'application/octet-stream';

        $map = [
            'pdf' => 'application/pdf',
            'eml' => 'message/rfc822',
            'jpg' => 'image/jpeg',
            'jpeg'=> 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'doc' => 'application/msword',
            'docx'=> 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];

        $extLower = strtolower($ext);
        if (isset($map[$extLower])) return $map[$extLower];

        // Symfony MimeTypes fallback (if available)
        if (class_exists(MimeTypes::class)) {
            $guess = MimeTypes::getDefault()->guessMimeType($filename);
            if ($guess) return $guess;
        }

        return 'application/octet-stream';
    }
}
