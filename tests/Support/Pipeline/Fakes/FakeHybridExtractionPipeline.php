<?php

namespace Tests\Support\Pipeline\Fakes;

use App\Services\Extraction\HybridExtractionPipeline;
use Illuminate\Support\Str;

class FakeHybridExtractionPipeline extends HybridExtractionPipeline
{
    public function __construct()
    {
        // Skip parent dependencies
    }

    public function extract(string $content, string $source = 'email'): array
    {
        $hasBmw = Str::contains(Str::upper($content), 'BMW');
        $hasSerie7 = Str::contains($content, 'Série 7');
        $origin = Str::contains(Str::upper($content), 'BRUXELLES') ? 'Bruxelles' : 'Antwerp';
        $destination = Str::contains(Str::upper($content), 'DJEDDAH') ? 'Djeddah' : 'Lagos';
        $shippingType = Str::contains(Str::upper($content), 'RORO') ? 'roro' : 'seafreight';

        return [
            'data' => [
                'vehicle' => [
                    'brand' => $hasBmw ? 'BMW' : 'Generic',
                    'model' => $hasSerie7 ? 'Série 7' : 'Model',
                    'dimensions' => [
                        'length_m' => 5.3,
                        'width_m' => 1.9,
                        'height_m' => 1.4,
                    ],
                    'needs_dimension_lookup' => false,
                ],
                'shipment' => [
                    'origin' => $origin,
                    'destination' => $destination,
                    'shipping_type' => $shippingType,
                ],
                'shipping' => [
                    'route' => [
                        'origin' => ['city' => $origin],
                        'destination' => ['city' => $destination],
                    ],
                ],
                'contact' => [
                    'name' => 'Badr Algothami',
                    'email' => 'badr.algothami@gmail.com',
                ],
            ],
            'meta' => [
                'source' => $source,
                'confidence' => 0.92,
            ],
        ];
    }
}

