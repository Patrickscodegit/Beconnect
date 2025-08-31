<?php

namespace App\Support;

use Illuminate\Support\Str;

trait JsonExtractorTrait
{
    protected function ensureJsonStringToArray(?string $s): array
    {
        $s = trim((string)$s);

        // remove fenced code blocks if present
        if (Str::startsWith($s, '```')) {
            $s = preg_replace('/^```[a-zA-Z]*\n|\n```$/', '', $s);
        }

        $decoded = json_decode($s, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // salvage first {...} block
        if (preg_match('/\{[\s\S]*\}/', $s, $m)) {
            $decoded = json_decode($m[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        throw new \RuntimeException('AI response was not valid JSON. Snippet: ' . Str::limit($s, 400));
    }
}
