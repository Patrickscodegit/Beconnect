<?php

namespace App\Support;

class PortAliasNormalizer
{
    /**
     * Normalize alias string for consistent lookup.
     * Rules:
     * - trim
     * - mb_strtolower
     * - collapse whitespace to single spaces
     * - strip trailing punctuation (",", ".", ";", ":") and surrounding quotes
     *
     * @param string $alias
     * @return string
     */
    public static function normalize(string $alias): string
    {
        // trim
        $normalized = trim($alias);
        // mb_strtolower
        $normalized = mb_strtolower($normalized, 'UTF-8');
        // collapse whitespace to single spaces
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        // strip trailing punctuation (",", ".", ";", ":") and surrounding quotes
        $normalized = trim($normalized, " \t\n\r\0\x0B\"'.,;:");
        return $normalized;
    }
}

