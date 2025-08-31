<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\RobawsClient;
use App\Services\RobawsIntegration\JsonFieldMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RobawsRepairOfferFields extends Command
{
    protected $signature = 'robaws:repair-offer-fields {offer_id}';
    protected $description = 'Repair an existing Robaws offer by updating it with properly mapped fields';

    public function __construct(
        private RobawsClient $robawsClient,
        private JsonFieldMapper $fieldMapper
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $offerId = $this->argument('offer_id');
        
        $this->info("Repairing Robaws offer: {$offerId}");
        
        try {
            // Find the document associated with this offer
            $document = Document::where('robaws_quotation_id', $offerId)->first();
            
            if (!$document) {
                $this->error("No document found for Robaws offer ID: {$offerId}");
                return 1;
            }
            
            $this->info("Found document: {$document->id} ({$document->original_filename})");
            
            // Get extraction data
            $extraction = $document->extractions()->first();
            $extractedData = null;
            
            if ($extraction && $extraction->raw_json) {
                $extractedData = json_decode($extraction->raw_json, true);
            } elseif ($document->raw_json) {
                $extractedData = json_decode($document->raw_json, true);
            } else {
                $extractedData = $document->extraction_data;
            }
            
            if (empty($extractedData)) {
                $this->error("No extraction data available for document {$document->id}");
                return 1;
            }
            
            // Map fields
            $mapped = $this->fieldMapper->mapFields($extractedData);
            $this->info("Mapped " . count($mapped) . " fields");
            
            // Build extraFields
            $extraFields = $this->buildExtraFieldsFromMapped($mapped);
            $this->info("Built extraFields for: " . implode(', ', array_keys($extraFields)));
            
            // GET → merge → PUT
            $this->info("Getting current offer from Robaws...");
            $remote = $this->robawsClient->getOffer($offerId);
            
            $payload = $this->stripOfferReadOnly($remote);
            $payload['extraFields'] = array_merge(
                $remote['extraFields'] ?? [],
                $extraFields
            );
            
            $this->info("Updating offer with " . count($payload['extraFields']) . " extraFields...");
            $this->robawsClient->updateOffer($offerId, $payload);
            
            $this->info("✅ Successfully repaired offer {$offerId}");
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("Failed to repair offer: " . $e->getMessage());
            Log::error('RobawsRepairOfferFields failed', [
                'offer_id' => $offerId,
                'error' => $e->getMessage()
            ]);
            return 1;
        }
    }
    
    private function buildExtraFieldsFromMapped(array $m): array
    {
        $xf = [];
        $put = function (string $label, $value, string $type = 'stringValue') use (&$xf) {
            if ($value === null || $value === '') return;
            $xf[$label] = [$type => (string)$value];
        };

        // Quotation info
        $put('Customer', $m['customer'] ?? null);
        $put('Contact', $m['contact'] ?? null);
        $put('Endcustomer', $m['endcustomer'] ?? null);
        $put('Customer reference', $m['customer_reference'] ?? null);

        // Routing
        $put('POR', $m['por'] ?? null);
        $put('POL', $m['pol'] ?? null);
        $put('POT', $m['pot'] ?? null);
        $put('POD', $m['pod'] ?? null);
        $put('FDEST', $m['fdest'] ?? null);

        // Cargo
        $put('CARGO', $m['cargo'] ?? null);
        $put('DIM_BEF_DELIVERY', $m['dim_bef_delivery'] ?? null);

        // JSON
        if (!empty($m['JSON'])) {
            $xf['JSON'] = ['stringValue' => $m['JSON']];
        }

        return $xf;
    }
    
    private function stripOfferReadOnly(array $offer): array
    {
        unset($offer['id'], $offer['createdAt'], $offer['updatedAt'], $offer['links'], $offer['number']);
        return $offer;
    }
}
