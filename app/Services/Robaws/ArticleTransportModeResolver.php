<?php

namespace App\Services\Robaws;

use Illuminate\Support\Str;

class ArticleTransportModeResolver
{
    /**
     * Canonical transport modes supported by article cache.
     */
    private const CANONICAL_MODES = [
        'RORO',
        'FCL',
        'FCL CONSOL',
        'LCL',
        'AIRFREIGHT',
        'BB',
        'ROAD TRANSPORT',
        'CUSTOMS',
        'SEAFREIGHT',
        'WAREHOUSE',
        'HOMOLOGATION',
    ];

    /**
     * Known RORO carriers that typically indicate RoRo transport mode.
     */
    private const RORO_CARRIERS = [
        'SALLAUM',
        'SALLAUM LINES',
        'GRIMALDI',
        'HOEGH',
        'HÖEGH',
        'HOEGH AUTOLINERS',
        'NMT',
        'EUKOR',
        'NYK',
        'K LINE',
        'K-LINE',
        'GLOVIS',
        'WALLENIUS',
        'WILHELMSEN',
        'WALLENIUS WILHELMSEN',
        'ACL',
        'STARSEA',
        'ARC',
        'WWL',
    ];

    /**
     * Vehicle-oriented commodity types that typically ship via RORO.
     */
    private const VEHICLE_COMMODITIES = [
        'CAR',
        'SUV',
        'SMALL VAN',
        'BIG VAN',
        'TRUCK',
        'TRUCKHEAD',
        'BUS',
        'MOTORCYCLE',
        'LM CARGO',
        'LM',
        'HH',
        'MACHINERY',
        'TRAILER',
    ];

    /**
     * Resolve the best transport mode for an article based on context.
     *
     * @param string $articleName
     * @param array<string, mixed> $context
     */
    public function resolve(string $articleName, array $context = []): ?string
    {
        $base = $this->normalize($context['transport_mode'] ?? null);
        $upperName = Str::upper($articleName);
        $upperDescription = Str::upper((string) ($context['description'] ?? ''));
        $articleCode = Str::upper((string) ($context['article_code'] ?? ''));

        $text = trim($upperName . ' ' . $upperDescription . ' ' . $articleCode);

        // Explicit container keywords override everything else.
        if ($this->containsAny($text, ['FCL', 'CONTAINER', '20FT', '40FT', 'TEU'])) {
            return 'FCL';
        }

        if ($this->containsAny($text, ['LCL'])) {
            return 'LCL';
        }

        if ($this->containsAny($text, ['AIRFREIGHT', 'AIR FREIGHT', 'AIR '])) {
            return 'AIRFREIGHT';
        }

        if ($this->containsAny($text, ['BREAK BULK', 'BREAKBULK', 'BB'])) {
            return 'BB';
        }

        if ($this->containsAny($text, ['ROAD TRANSPORT', 'ROAD ', ' TRUCK ', 'TRUCKING'])) {
            return 'ROAD TRANSPORT';
        }

        if ($this->containsAny($text, ['CUSTOMS'])) {
            return 'CUSTOMS';
        }

        // Determine if the article is likely RORO.
        $shippingLine = Str::upper((string) ($context['shipping_line'] ?? ''));
        $commodityType = Str::upper((string) ($context['commodity_type'] ?? ''));
        $category = Str::upper((string) ($context['category'] ?? ''));

        $hasRoroKeyword = $this->containsAny($text, [
            'RORO',
            'RO-RO',
            'ROLL ON',
            'ROLL-ON',
            'RO/RO',
            'POV',
            'LANE METER',
            'LANEMETER',
            'HIGH AND HEAVY',
            'SALLAUM',
            'GRIMALDI',
            'HOEGH',
            'HÖEGH',
            'WALLENIUS',
            'WILHELMSEN',
            'EUKOR',
            'NYK',
            'K-LINE',
            'K LINE',
            'STARSEA',
            'ACL ',
            'ACL(',
            'NMT(',
            'NMT ',
            'LM SEAFREIGHT',
            'LM CARGO',
        ]);

        $vehicleCommodity = in_array($commodityType, self::VEHICLE_COMMODITIES, true)
            || in_array($category, self::VEHICLE_COMMODITIES, true);

        $shippingLineIsRoro = $this->containsAny($shippingLine, self::RORO_CARRIERS);

        if (
            $hasRoroKeyword
            || ($vehicleCommodity && $shippingLineIsRoro)
            || ($shippingLineIsRoro && $this->containsAny($text, ['SEAFREIGHT', 'SEA FREIGHT']))
        ) {
            return 'RORO';
        }

        if ($this->containsAny($text, ['HOMOLOGATION'])) {
            return 'HOMOLOGATION';
        }

        if ($this->containsAny($text, ['WAREHOUSE'])) {
            return 'WAREHOUSE';
        }

        // Default fallback to base if it is a known mode.
        if ($base) {
            return $base;
        }

        // If nothing matches but sea freight is mentioned, keep SEAFREIGHT.
        if ($this->containsAny($text, ['SEAFREIGHT', 'SEA FREIGHT'])) {
            return 'SEAFREIGHT';
        }

        return null;
    }

    /**
     * Normalize raw transport mode string to canonical form.
     */
    public function normalize(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $upper = Str::upper(trim($value));

        $mapping = [
            'RO-RO' => 'RORO',
            'RO/RO' => 'RORO',
            'RO RO' => 'RORO',
            'ROLL ON ROLL OFF' => 'RORO',
            'ROLL-ON/ROLL-OFF' => 'RORO',
            'RO-RO EXPORT' => 'RORO',
            'RO-RO IMPORT' => 'RORO',
            'RORO EXPORT' => 'RORO',
            'RORO IMPORT' => 'RORO',
            'RORO EXPORT/IMPORT' => 'RORO',
            'RORO IMPORT/EXPORT' => 'RORO',
            'FCL CONSOLIDATION' => 'FCL CONSOL',
            'BB' => 'BB',
        ];

        if (isset($mapping[$upper])) {
            return $mapping[$upper];
        }

        foreach (self::CANONICAL_MODES as $mode) {
            if ($upper === $mode) {
                return $mode;
            }
        }

        // Allow partial matches like "RORO - Seafreight"
        foreach (self::CANONICAL_MODES as $mode) {
            if (str_contains($upper, $mode)) {
                return $mode;
            }
        }

        return null;
    }

    /**
     * Helper to detect if haystack contains any of the provided needles.
     *
     * @param iterable<string> $needles
     */
    private function containsAny(string $haystack, iterable $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }

            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}

