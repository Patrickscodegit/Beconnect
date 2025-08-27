<?php

namespace App\Console\Commands;

use App\Models\Intake;
use Illuminate\Console\Command;

class TestCopyDebug extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:copy-debug {intake?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug the copy functionality by showing the text that should be copied';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $intakeId = $this->argument('intake') ?? 71;
        
        $intake = Intake::find($intakeId);
        
        if (!$intake) {
            $this->error("Intake #{$intakeId} not found");
            return;
        }
        
        $extraction = $intake->extraction;
        
        if (!$extraction) {
            $this->error("No extraction found for intake #{$intakeId}");
            return;
        }
        
        $this->info("Testing Copy Functionality for Intake #{$intakeId}");
        $this->line('================================================');
        
        // Simulate the exact same logic as the blade template
        $data = $extraction->extracted_data ?? $extraction->raw_json;
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        
        $this->line('Raw Data Structure:');
        $this->line(json_encode($data, JSON_PRETTY_PRINT));
        
        $this->line('');
        $this->line('Formatted Text (What should be copied):');
        $this->line('========================================');
        
        // Replicate the exact formatting logic from the blade template
        $output = $this->formatExtractedData($data);
        
        $this->line($output);
        
        $this->line('');
        $this->info('Text length: ' . strlen($output) . ' characters');
        $this->info('Text preview (first 100 chars): ' . substr($output, 0, 100) . '...');
        
        // Save to a file for testing
        file_put_contents('/tmp/copy_test.txt', $output);
        $this->info('Text saved to /tmp/copy_test.txt for manual testing');
    }
    
    private function formatExtractedData($data)
    {
        $output = '';
        
        // Contact Information Section
        if (isset($data['contact']) || isset($data['contact_info'])) {
            $contact = $data['contact'] ?? $data['contact_info'];
            $output .= "CONTACT INFORMATION\n";
            $output .= "==================\n";
            
            if (isset($contact['name'])) {
                $output .= "Name: " . $contact['name'] . "\n";
            }
            if (isset($contact['phone'])) {
                $output .= "Phone: " . $contact['phone'] . "\n";
            }
            if (isset($contact['email'])) {
                $output .= "Email: " . $contact['email'] . "\n";
            }
            $output .= "\n";
        }

        // Shipping Details Section
        if (isset($data['shipment']) || isset($data['shipping'])) {
            $shipment = $data['shipment'] ?? $data['shipping'];
            $output .= "SHIPPING DETAILS\n";
            $output .= "================\n";
            
            if (isset($shipment['origin'])) {
                $output .= "Origin: " . $shipment['origin'] . "\n";
            }
            if (isset($shipment['destination'])) {
                $output .= "Destination: " . $shipment['destination'] . "\n";
            }
            $output .= "\n";
        }

        // Vehicle Information Section
        $vehicle = null;
        if (isset($data['vehicle'])) {
            $vehicle = $data['vehicle'];
        } elseif (isset($data['vehicle_details'])) {
            $vehicle = $data['vehicle_details'];
        } elseif (isset($data['vehicle_info'])) {
            $vehicle = $data['vehicle_info'];
        } elseif (isset($data['shipment']['vehicle'])) {
            $vehicle = $data['shipment']['vehicle'];
        }

        if ($vehicle) {
            $output .= "VEHICLE INFORMATION\n";
            $output .= "===================\n";
            
            if (isset($vehicle['type'])) {
                $output .= "Type: " . $vehicle['type'] . "\n";
            }
            $output .= "\n";
        }

        return trim($output);
    }
}
