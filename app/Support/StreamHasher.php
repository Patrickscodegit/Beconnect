<?php

namespace App\Support;

class StreamHasher
{
    /**
     * Read $src in chunks, compute SHA-256 and mirror into a temp stream.
     * Returns ['stream' => resource, 'sha256' => string, 'size' => int].
     */
    public static function toTempHashedStream($src, int $chunk = 1024 * 1024): array
    {
        if (!is_resource($src)) {
            throw new \InvalidArgumentException('Source stream is not a resource.');
        }

        $tmp = fopen('php://temp', 'w+');
        $hash = hash_init('sha256');
        $size = 0;

        while (!feof($src)) {
            $buf = fread($src, $chunk);
            if ($buf === '' || $buf === false) break;
            $size += strlen($buf);
            hash_update($hash, $buf);
            fwrite($tmp, $buf);
        }

        @rewind($tmp);

        return [
            'stream'  => $tmp,
            'sha256'  => hash_final($hash),
            'size'    => $size,
        ];
    }
}
