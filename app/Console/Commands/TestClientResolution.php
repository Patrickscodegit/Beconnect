<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Intake;

class TestClientResolution extends Command
{
    protected $signature = 'test:client-resolution {intake_id}';
    protected $description = 'Test client resolution for Armos BV';

    public function handle()
    {
        $intakeId = $this->argument('intake_id');
        $intake = Intake::find($intakeId);
        
        if (!$intake) {
            $this->error("Intake not found!");
            return 1;
        }
        
        $this->info("Testing Client Resolution");
        $this->info("Customer: " . $intake->customer_name);
        $this->info("Email: " . $intake->contact_email);
        
        try {
            $apiClient = app(\App\Services\Export\Clients\RobawsApiClient::class);
            
            // Test with different combinations
            $tests = [
                ['Nancy Deckers', 'nancy@armos.be'],
                ['Armos BV', 'nancy@armos.be'],
                [null, 'nancy@armos.be']
            ];
            
            foreach ($tests as $i => [$name, $email]) {
                $this->info("\nTest " . ($i + 1) . ": findClientId('$name', '$email')");
                try {
                    $clientId = $apiClient->findClientId($name, $email);
                    $this->info("   Result: " . ($clientId ?? 'null'));
                } catch (\Exception $e) {
                    $this->error("   Error: " . $e->getMessage());
                }
            }
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
        
        return 0;
    }
}
