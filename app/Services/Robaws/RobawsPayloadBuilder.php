<?php

namespace App\Services\Robaws;

class RobawsPayloadBuilder
{
    /**
     * Build Robaws payload from extraction data
     */
    public static function build(array $ex): array
    {
        $brand = trim((string) data_get($ex, 'vehicle.brand', ''));
        $model = trim((string) data_get($ex, 'vehicle.model', ''));
        $cargo = trim($brand.' '.$model);

        $len = data_get($ex, 'vehicle.dimensions.length_m');
        $wid = data_get($ex, 'vehicle.dimensions.width_m');
        $hei = data_get($ex, 'vehicle.dimensions.height_m');
        $dims = self::formatDims($len, $wid, $hei);

        $originCity = data_get($ex, 'shipping.route.origin.city') ?: data_get($ex, 'shipment.origin');
        $destCity   = data_get($ex, 'shipping.route.destination.city') ?: data_get($ex, 'shipment.destination');

        $method = strtolower((string)(data_get($ex, 'shipping.method') ?: data_get($ex, 'shipment.shipping_type', '')));
        $method = self::canonicalMethod($method);

        return [
            // Core fields
            'status'   => 'DRAFT',
            'assignee' => config('services.robaws.default_assignee', 'sales@truck-time.com'),
            'title'    => self::makeTitle($brand, $model, $originCity, $destCity, $method),

            // Custom fields (map to your Robaws extra fields)
            'customFields' => [
                'POR'                => $originCity,   // place of receipt / origin
                'POL'                => $originCity,   // if you mirror city→port or enrich later
                'POD'                => $destCity,
                'CARGO'              => $cargo ?: null,
                'DIM_BEF_DELIVERY'   => $dims,
                'SHIPPING_METHOD'    => strtoupper($method ?: 'UNKNOWN'),
                'CONTACT_NAME'       => data_get($ex, 'contact.name'),
                'CONTACT_EMAIL'      => data_get($ex, 'contact.email'),
                'CONTACT_PHONE'      => data_get($ex, 'contact.phone'),
                'EXTRACTION_QUALITY' => round((data_get($ex, 'final_validation.quality_score', 0) * 100), 1) . '%',
                'DATA_COMPLETENESS'  => round((data_get($ex, 'final_validation.completeness_score', 0) * 100), 1) . '%',
            ],

            // Raw JSON for traceability in Robaws
            'JSON' => json_encode($ex, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Format dimensions string
     */
    private static function formatDims($l, $w, $h): ?string
    {
        if (!is_numeric($l) || !is_numeric($w) || !is_numeric($h)) return null;
        return number_format($l, 3, '.', '').' x '.number_format($w, 3, '.', '').' x '.number_format($h, 3, '.', '').' m';
    }

    /**
     * Canonicalize shipping method
     */
    private static function canonicalMethod(string $m): ?string
    {
        if ($m === '') return null;
        return match (true) {
            str_contains($m, 'roro')      => 'roro',
            str_contains($m, 'container') => 'container',
            str_contains($m, 'air')       => 'air',
            str_contains($m, 'lcl')       => 'lcl',
            str_contains($m, 'fcl')       => 'fcl',
            default                       => $m,
        };
    }

    /**
     * Generate meaningful title for quotation
     */
    private static function makeTitle(string $brand, string $model, ?string $o, ?string $d, ?string $method): string
    {
        $parts = array_filter([
            trim($brand.' '.$model),
            $method ? strtoupper($method) : null,
            ($o && $d) ? ($o.' → '.$d) : null,
        ]);
        return implode(' — ', $parts) ?: 'Quotation';
    }

    /**
     * Validate payload completeness
     */
    public static function validatePayload(array $payload): array
    {
        $required = ['POR', 'POD', 'CARGO', 'CONTACT_NAME'];
        $missing = [];
        
        foreach ($required as $field) {
            if (empty($payload['customFields'][$field])) {
                $missing[] = $field;
            }
        }

        $recommended = ['POL', 'DIM_BEF_DELIVERY', 'CONTACT_EMAIL', 'SHIPPING_METHOD'];
        $missingRecommended = [];
        
        foreach ($recommended as $field) {
            if (empty($payload['customFields'][$field])) {
                $missingRecommended[] = $field;
            }
        }

        return [
            'valid' => empty($missing),
            'missing_required' => $missing,
            'missing_recommended' => $missingRecommended,
            'completeness_score' => 1 - (count($missing) + count($missingRecommended) * 0.5) / (count($required) + count($recommended)),
        ];
    }
}
