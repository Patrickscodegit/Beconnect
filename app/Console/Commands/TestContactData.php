<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TestContactData extends Command
{
    protected $signature = 'test:contact-data {intake_id}';
    protected $description = 'Test contact data extraction for debugging';

    public function handle()
    {
        $intakeId = $this->argument('intake_id');
        
        try {
            $intake = \App\Models\Intake::find($intakeId);
            if (!$intake) {
                $this->error("Intake {$intakeId} not found!");
                return 1;
            }
            
            $mapper = app(\App\Services\Export\Mappers\RobawsMapper::class);
            $mapped = $mapper->mapIntakeToRobaws($intake);
            
            $this->info("=== CUSTOMER DATA ===");
            $customerData = $mapped['customer_data'] ?? [];
            
            if (isset($customerData['contact_person'])) {
                $this->info("Contact Person Data:");
                foreach ($customerData['contact_person'] as $key => $value) {
                    $this->info("  {$key}: " . ($value ?? 'null'));
                }
            } else {
                $this->info("No contact_person found in customer data");
            }
            
            // Also check raw extraction data
            $extractionData = $mapped['extraction_data'] ?? [];
            $contact = $extractionData['contact'] ?? [];
            
            $this->info("\n=== RAW CONTACT DATA ===");
            if (!empty($contact)) {
                foreach ($contact as $key => $value) {
                    $this->info("  {$key}: " . ($value ?? 'null'));
                }
            } else {
                $this->info("No contact found in extraction data");
            }
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
        
        return 0;
    }
}
