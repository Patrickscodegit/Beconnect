<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\Extraction\HybridExtractionPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FrenchEmailExtractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_extracts_french_route_and_contact_and_backfills_legacy_shipment()
    {
        // Create sample French BMW email content
        $frenchEmailContent = "
Bonjour,

Je souhaite expédier ma voiture BMW Série 7 de Bruxelles vers Djeddah par transport maritime (RoRo).

Merci d'inclure dans votre offre :
• Le transport maritime RoRo.
• L'accomplissement de toutes les formalités douanières et administratives jusqu'à la livraison à Djeddah.
• L'ajout d'une assurance tous risques pour le véhicule, si nécessaire.
• Le délai estimatif pour l'expédition.
• Un devis détaillé et complet.

Badr Algothami
";

        $pipeline = app(HybridExtractionPipeline::class);
        
        $result = $pipeline->extract($frenchEmailContent, 'email');
        $data = $result['data'];

        // Vehicle extraction
        $this->assertEquals('BMW', data_get($data, 'vehicle.brand'));
        $this->assertEquals('Série 7', data_get($data, 'vehicle.model'));

        // Shipping data consistency
        $this->assertEquals('Bruxelles', data_get($data, 'shipment.origin'));
        $this->assertEquals('Djeddah', data_get($data, 'shipment.destination'));
        $this->assertEquals('roro', data_get($data, 'shipment.shipping_type'));

        // Contact extraction
        $this->assertStringContainsString('Badr', data_get($data, 'contact.name', ''));
        $this->assertStringContainsString('Algothami', data_get($data, 'contact.name', ''));

        // Modern shipping structure should also be present
        $this->assertEquals('Bruxelles', data_get($data, 'shipping.route.origin.city'));
        $this->assertEquals('Djeddah', data_get($data, 'shipping.route.destination.city'));

        // Dimension lookup should be false if dimensions are present
        if (data_get($data, 'vehicle.dimensions.length_m')) {
            $this->assertFalse(data_get($data, 'vehicle.needs_dimension_lookup'));
        }

        // No placeholder values should remain
        $this->assertNotEquals('N/A', data_get($data, 'vehicle.brand'));
        $this->assertNotEquals('UNKNOWN', data_get($data, 'vehicle.model'));
    }

    public function test_robaws_payload_mapping()
    {
        $frenchEmailContent = "
Je souhaite expédier ma voiture BMW Série 7 de Bruxelles vers Djeddah par transport maritime (RoRo).

Badr Algothami
";

        $pipeline = app(HybridExtractionPipeline::class);
        $result = $pipeline->extract($frenchEmailContent, 'email');
        $data = $result['data'];

        // Test Robaws field mapping
        $por = data_get($data, 'shipment.origin');
        $pod = data_get($data, 'shipment.destination');
        $brand = data_get($data, 'vehicle.brand');
        $model = data_get($data, 'vehicle.model');

        $this->assertNotNull($por);
        $this->assertNotNull($pod);
        $this->assertEquals('Bruxelles', $por);
        $this->assertEquals('Djeddah', $pod);

        $cargo = trim(($brand ?? '') . ' ' . ($model ?? ''));
        $this->assertEquals('BMW Série 7', $cargo);

        // Test dimensions if present
        $len = data_get($data, 'vehicle.dimensions.length_m');
        $wid = data_get($data, 'vehicle.dimensions.width_m');
        $hei = data_get($data, 'vehicle.dimensions.height_m');

        if ($len && $wid && $hei) {
            $dimensions = sprintf('%.3f x %.3f x %.3f m', $len, $wid, $hei);
            $this->assertStringContainsString('x', $dimensions);
            $this->assertStringContainsString('m', $dimensions);
        }
    }
}
