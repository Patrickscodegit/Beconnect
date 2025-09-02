<?php

namespace App\Services\Robaws;

final class NameNormalizer
{
    private const LEGAL_SUFFIXES = ['bv','bvba','nv','sprl','srl','gmbh','sarl','sas','ltd','plc','sa','oy','ab'];

    public static function normalize(string $s): string
    {
        $s = \Normalizer::normalize($s, \Normalizer::FORM_D);
        $s = preg_replace('/\p{Mn}+/u', '', $s);
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
        $s = preg_replace('/\s+/', ' ', trim($s));
        $parts = explode(' ', $s);
        if ($parts && in_array(end($parts), self::LEGAL_SUFFIXES, true)) array_pop($parts);
        return implode(' ', $parts);
    }

    public static function similarity(string $a, string $b): float
    {
        similar_text(self::normalize($a), self::normalize($b), $pct);
        return $pct;
    }
}
