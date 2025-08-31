<?php

namespace App\Utils;

class RobawsPayloadMapper
{
    /**
     * Map extraction data to Robaws payload format
     */
    public static function mapExtraFields(array $extractionData): array
    {
        $por = data_get($extractionData, 'shipment.origin');
        $pod = data_get($extractionData, 'shipment.destination');
        $brand = data_get($extractionData, 'vehicle.brand');
        $model = data_get($extractionData, 'vehicle.model');

        $len = data_get($extractionData, 'vehicle.dimensions.length_m');
        $wid = data_get($extractionData, 'vehicle.dimensions.width_m');
        $hei = data_get($extractionData, 'vehicle.dimensions.height_m');

        $extraFields = array_filter([
            'POR' => $por,
            'POL' => $por, // equate origin to POL when POL unknown
            'POD' => $pod,
            'CARGO' => trim(($brand ?? '') . ' ' . ($model ?? '')) ?: null,
            'DIM_BEF_DELIVERY' => ($len && $wid && $hei) ? sprintf('%.3f x %.3f x %.3f m', $len, $wid, $hei) : null,
            'Customer' => data_get($extractionData, 'contact.name'),
            'Customer_reference' => data_get($extractionData, 'shipment.reference'),
            'Contact' => data_get($extractionData, 'contact.email'),
        ], fn($v) => !is_null($v) && $v !== '');

        return $extraFields;
    }

    /**
     * Build complete Robaws offer payload
     */
    public static function buildOfferPayload(array $extractionData, array $baseOffer = []): array
    {
        $extraFields = self::mapExtraFields($extractionData);
        
        return array_merge($baseOffer, [
            'extraFields' => $extraFields,
            'cargo_description' => data_get($extraFields, 'CARGO'),
            'origin_port' => data_get($extraFields, 'POR'),
            'destination_port' => data_get($extraFields, 'POD'),
            'dimensions' => data_get($extraFields, 'DIM_BEF_DELIVERY'),
        ]);
    }
}
