<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Support\EmailFingerprint;
use App\Services\Robaws\RobawsPayloadBuilder;
use App\Services\Extraction\HybridExtractionPipeline;
use Tests\Support\Pipeline\PipelineTestHelper;

/** @group pipeline */
class EmailProcessingTest extends TestCase
{
    protected function setUp(): void
    {
        PipelineTestHelper::prepare();
        parent::setUp();

        PipelineTestHelper::boot($this);
    }

    /** @test */
    public function it_processes_bmw_french_email_correctly()
    {
        // Sample BMW French email content
        $frenchContent = "Bonjour,

Je souhaite expédier ma voiture BMW Série 7 de Bruxelles vers Djeddah par transport maritime (RoRo).

Merci d'inclure dans votre offre :
• Le transport maritime RoRo.
• L'accomplissement de toutes les formalités douanières et administratives jusqu'à la livraison à Djeddah.
• L'ajout d'une assurance tous risques pour le véhicule, si nécessaire.
• Le délai estimatif pour l'expédition.
• Un devis détaillé et complet.

Badr algothami";

        // Mock email headers
        $headers = [
            'from' => 'Badr Algothami <badr.algothami@gmail.com>',
            'to' => 'sales@belgaco.be',
            'subject' => 'Demande de transport maritime RoRo – BMW Série 7',
            'message-id' => '<test-123@gmail.com>',
        ];

        // Test fingerprinting
        $fingerprint = EmailFingerprint::fromRaw('', $headers, $frenchContent);
        
        $this->assertNotNull($fingerprint['message_id']);
        $this->assertEquals('test-123@gmail.com', $fingerprint['message_id']);
        $this->assertNotNull($fingerprint['content_sha']);
        $this->assertEquals(64, strlen($fingerprint['content_sha']));

        // Test extraction
        $pipeline = app(HybridExtractionPipeline::class);
        $result = $pipeline->extract($frenchContent, 'email');
        $data = $result['data'] ?? [];

        // Add header email
        if (!data_get($data, 'contact.email')) {
            data_set($data, 'contact.email', 'badr.algothami@gmail.com');
        }

        // Test extraction results
        $this->assertEquals('BMW', data_get($data, 'vehicle.brand'));
        $this->assertEquals('Série 7', data_get($data, 'vehicle.model'));
        $this->assertEquals('Bruxelles', data_get($data, 'shipment.origin'));
        $this->assertEquals('Djeddah', data_get($data, 'shipment.destination'));
        $this->assertEquals('Badr Algothami', data_get($data, 'contact.name'));
        $this->assertEquals('badr.algothami@gmail.com', data_get($data, 'contact.email'));

        $routeOrigin = data_get($data, 'shipping.route.origin.city', data_get($data, 'shipment.origin'));
        $routeDestination = data_get($data, 'shipping.route.destination.city', data_get($data, 'shipment.destination'));

        $this->assertEquals(data_get($data, 'shipment.origin'), $routeOrigin);
        $this->assertEquals(data_get($data, 'shipment.destination'), $routeDestination);

        // Test Robaws payload building
        $payload = RobawsPayloadBuilder::build($data);
        $validation = RobawsPayloadBuilder::validatePayload($payload);

        $this->assertEquals('BMW Série 7 — RORO — Bruxelles → Djeddah', $payload['title']);
        $this->assertEquals('DRAFT', $payload['status']);
        $this->assertEquals('Bruxelles', $payload['customFields']['POR']);
        $this->assertEquals('Djeddah', $payload['customFields']['POD']);
        $this->assertEquals('BMW Série 7', $payload['customFields']['CARGO']);
        $this->assertEquals('Badr Algothami', $payload['customFields']['CONTACT_NAME']);
        $this->assertEquals('badr.algothami@gmail.com', $payload['customFields']['CONTACT_EMAIL']);

        // Test validation
        $this->assertTrue($validation['valid']);
        $this->assertGreaterThan(0.8, $validation['completeness_score']);
    }

    /** @test */
    public function it_creates_consistent_fingerprints()
    {
        $content = "Test email content";
        $headers = ['from' => 'test@example.com', 'subject' => 'Test'];

        $fingerprint1 = EmailFingerprint::fromRaw('', $headers, $content);
        $fingerprint2 = EmailFingerprint::fromRaw('', $headers, $content);

        $this->assertEquals($fingerprint1['content_sha'], $fingerprint2['content_sha']);

        // Different content should produce different fingerprints
        $fingerprint3 = EmailFingerprint::fromRaw('', $headers, $content . ' modified');
        $this->assertNotEquals($fingerprint1['content_sha'], $fingerprint3['content_sha']);
    }

    /** @test */
    public function it_validates_robaws_payload_correctly()
    {
        // Complete payload
        $completeData = [
            'vehicle' => ['brand' => 'BMW', 'model' => 'Série 7', 'dimensions' => ['length_m' => 5.3, 'width_m' => 1.9, 'height_m' => 1.4]],
            'shipment' => ['origin' => 'Brussels', 'destination' => 'Jeddah', 'shipping_type' => 'roro'],
            'contact' => ['name' => 'John Doe', 'email' => 'john@example.com'],
        ];

        $payload = RobawsPayloadBuilder::build($completeData);
        $validation = RobawsPayloadBuilder::validatePayload($payload);

        $this->assertTrue($validation['valid']);
        $this->assertEmpty($validation['missing_required']);

        // Incomplete payload
        $incompleteData = ['vehicle' => ['brand' => 'BMW']];
        $incompletePayload = RobawsPayloadBuilder::build($incompleteData);
        $incompleteValidation = RobawsPayloadBuilder::validatePayload($incompletePayload);

        $this->assertFalse($incompleteValidation['valid']);
        $this->assertNotEmpty($incompleteValidation['missing_required']);
    }
}
