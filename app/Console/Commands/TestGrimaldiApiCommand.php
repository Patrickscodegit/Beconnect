<?php

namespace App\Console\Commands;

use App\Services\Grimaldi\GrimaldiApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestGrimaldiApiCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'grimaldi:test 
                            {--trade=NEWAF_RORO : GrimaldiTrade parameter (NEWAF_RORO, NAWAF_RORO, SHORTSEA)}
                            {--days=40 : Number of days to look ahead}
                            {--pol=BEANR : Port of Loading (UN/LOCODE)}
                            {--pod=CIABJ : Port of Discharge (UN/LOCODE)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Grimaldi API SailingSchedule endpoint with specified parameters';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $trade = $this->option('trade');
        $days = (int) $this->option('days');
        $pol = $this->option('pol');
        $pod = $this->option('pod');

        $this->info("Testing Grimaldi API SailingSchedule endpoint");
        $this->line("Parameters:");
        $this->line("  Trade: {$trade}");
        $this->line("  Days: {$days}");
        $this->line("  POL: {$pol}");
        $this->line("  POD: {$pod}");
        $this->line("");

        try {
            $client = new GrimaldiApiClient();
            
            // Test authentication first
            $this->info("Step 1: Testing authentication...");
            $token = $client->getSecurityToken();
            
            if (!$token) {
                $this->error("❌ Failed to obtain security token");
                return Command::FAILURE;
            }
            
            $this->info("✅ Authentication successful");
            $this->line("");

            // Step 2: Probe base URL capabilities
            $this->info("Step 2: Probing base URL capabilities...");
            $baseUrls = [
                'BETA' => config('services.grimaldi.base_url_beta'),
                'PROD' => config('services.grimaldi.base_url_prod'),
            ];
            
            $capabilities = [];
            foreach ($baseUrls as $env => $baseUrl) {
                if (!$baseUrl) {
                    continue;
                }
                
                $this->line("  Testing {$env}: {$baseUrl}");
                
                // Use reflection to access private method
                $reflection = new \ReflectionClass($client);
                $probeMethod = $reflection->getMethod('probeBaseUrlCapabilities');
                $probeMethod->setAccessible(true);
                $caps = $probeMethod->invoke($client, $baseUrl);
                
                $capabilities[$env] = $caps;
                
                $infoStatus = $caps['info_status'] ?? 'N/A';
                $voyageStatus = $caps['sailing_schedule_voyage_status'] ?? 'N/A';
                $paramsStatus = $caps['sailing_schedule_params_status'] ?? 'N/A';
                
                $this->line("    Info endpoint: {$infoStatus}");
                $this->line("    SailingSchedule (VoyageNo): {$voyageStatus}");
                $this->line("    SailingSchedule (params): {$paramsStatus}");
                
                if ($infoStatus === 200 && ($voyageStatus === 200 || $paramsStatus === 200)) {
                    $this->info("    ✅ {$env} supports SailingSchedule");
                } else {
                    $this->warn("    ⚠️  {$env} does not fully support SailingSchedule");
                }
            }
            $this->line("");

            // Test SailingSchedule endpoint
            $this->info("Step 3: Testing SailingSchedule endpoint...");
            $schedules = $client->getSailingSchedule($pol, $pod, $days);
            
            // Determine which base URL was used
            $reflection = new \ReflectionClass($client);
            $workingBaseUrlProp = $reflection->getProperty('workingBaseUrl');
            $workingBaseUrlProp->setAccessible(true);
            $workingBaseUrl = $workingBaseUrlProp->getValue($client);
            
            if ($workingBaseUrl) {
                $envType = str_contains($workingBaseUrl, 'BETA') ? 'BETA' : 'PROD';
                $this->info("  Using base URL: {$envType} ({$workingBaseUrl})");
            }
            $this->line("");
            
            if (!is_array($schedules)) {
                $this->error("❌ SailingSchedule endpoint returned non-array response");
                $this->line("Response type: " . gettype($schedules));
                return Command::FAILURE;
            }
            
            if (empty($schedules)) {
                $this->warn("⚠️  SailingSchedule endpoint returned empty array (no schedules found)");
                $this->line("This might be normal if there are no schedules for the specified route");
                
                // Show recommendation
                $this->line("");
                $this->info("Recommendation:");
                $hasWorking = false;
                foreach ($capabilities as $env => $caps) {
                    if (($caps['sailing_schedule_voyage_status'] ?? null) === 200 || 
                        ($caps['sailing_schedule_params_status'] ?? null) === 200) {
                        $this->line("  ✅ Use {$env} environment (SailingSchedule available)");
                        $hasWorking = true;
                    }
                }
                if (!$hasWorking) {
                    $this->error("  ❌ Neither BETA nor PROD supports SailingSchedule");
                    $this->line("  Contact Grimaldi support");
                }
                
                return Command::SUCCESS;
            }
            
            $this->info("✅ SailingSchedule endpoint successful");
            $this->line("Found " . count($schedules) . " schedule(s)");
            $this->line("");
            
            // Display first schedule as sample
            $this->info("Sample schedule (first result):");
            $this->line(json_encode($schedules[0], JSON_PRETTY_PRINT));
            
            // Show recommendation
            $this->line("");
            $this->info("Recommendation:");
            if ($workingBaseUrl) {
                $envType = str_contains($workingBaseUrl, 'BETA') ? 'BETA' : 'PROD';
                $this->line("  ✅ Currently using {$envType} environment");
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("❌ Exception occurred: " . $e->getMessage());
            $this->line("");
            $this->line("Full error details:");
            $this->line($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}

