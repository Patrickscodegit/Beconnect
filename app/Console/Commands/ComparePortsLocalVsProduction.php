<?php

namespace App\Console\Commands;

use App\Models\Port;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ComparePortsLocalVsProduction extends Command
{
    protected $signature = 'ports:compare-local-production';
    protected $description = 'Compare ports between local and production databases';

    public function handle()
    {
        $this->info('🔍 Comparing ports: Local vs Production');
        $this->newLine();

        // Get local ports
        $localPorts = Port::orderBy('code')->get(['id', 'code', 'name', 'country', 'region', 'type', 'is_active', 'unlocode', 'country_code', 'port_category', 'iata_code', 'icao_code']);
        
        $this->info("📊 Local ports count: " . $localPorts->count());
        
        // Get production ports via SSH
        $this->info("📊 Fetching production ports...");
        $productionPortsJson = shell_exec("ssh forge@bconnect.64.226.120.45.nip.io 'cd app.belgaco.be && php artisan tinker --execute=\"echo json_encode(\\\\App\\\\Models\\\\Port::orderBy(\\\"code\\\")->get([\\\"id\\\", \\\"code\\\", \\\"name\\\", \\\"country\\\", \\\"region\\\", \\\"type\\\", \\\"is_active\\\", \\\"unlocode\\\", \\\"country_code\\\", \\\"port_category\\\", \\\"iata_code\\\", \\\"icao_code\\\"])->toArray());\"'");
        
        if (!$productionPortsJson) {
            $this->error('❌ Failed to fetch production ports');
            return 1;
        }
        
        $productionPorts = json_decode($productionPortsJson, true);
        if (!$productionPorts) {
            $this->error('❌ Failed to parse production ports JSON');
            return 1;
        }
        
        $this->info("📊 Production ports count: " . count($productionPorts));
        $this->newLine();

        // Compare
        $localCodes = $localPorts->pluck('code')->toArray();
        $productionCodes = array_column($productionPorts, 'code');
        
        $missingInProduction = array_diff($localCodes, $productionCodes);
        $missingInLocal = array_diff($productionCodes, $localCodes);
        $inBoth = array_intersect($localCodes, $productionCodes);
        
        // Display results
        $this->displayComparison($missingInProduction, $missingInLocal, $inBoth, $localPorts, $productionPorts, $localCodes, $productionCodes);
        
        return 0;
    }

    private function displayComparison(array $missingInProduction, array $missingInLocal, array $inBoth, $localPorts, array $productionPorts, array $localCodes, array $productionCodes): void
    {
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('📋 COMPARISON RESULTS');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->newLine();

        // Missing in production
        if (!empty($missingInProduction)) {
            $this->warn('⚠️  Ports in LOCAL but missing in PRODUCTION (' . count($missingInProduction) . '):');
            foreach ($missingInProduction as $code) {
                $port = $localPorts->firstWhere('code', $code);
                $this->line("   - {$code}: {$port->name} ({$port->country})");
            }
            $this->newLine();
        }

        // Missing in local
        if (!empty($missingInLocal)) {
            $this->warn('⚠️  Ports in PRODUCTION but missing in LOCAL (' . count($missingInLocal) . '):');
            foreach ($missingInLocal as $code) {
                $port = collect($productionPorts)->firstWhere('code', $code);
                $this->line("   - {$code}: {$port['name']} ({$port['country']})");
            }
            $this->newLine();
        }

        // In both - check for differences
        $differences = [];
        foreach ($inBoth as $code) {
            $local = $localPorts->firstWhere('code', $code);
            $prod = collect($productionPorts)->firstWhere('code', $code);
            
            $diff = [];
            if ($local->name !== ($prod['name'] ?? null)) $diff[] = "name: '{$local->name}' vs '{$prod['name']}'";
            if ($local->country !== ($prod['country'] ?? null)) $diff[] = "country: '{$local->country}' vs '{$prod['country']}'";
            if ($local->region !== ($prod['region'] ?? null)) $diff[] = "region: '{$local->region}' vs '{$prod['region']}'";
            if ($local->type !== ($prod['type'] ?? null)) $diff[] = "type: '{$local->type}' vs '{$prod['type']}'";
            if ($local->is_active !== ($prod['is_active'] ?? null)) $diff[] = "is_active: '{$local->is_active}' vs '{$prod['is_active']}'";
            if ($local->unlocode !== ($prod['unlocode'] ?? null)) $diff[] = "unlocode: '{$local->unlocode}' vs '{$prod['unlocode']}'";
            if ($local->country_code !== ($prod['country_code'] ?? null)) $diff[] = "country_code: '{$local->country_code}' vs '{$prod['country_code']}'";
            if ($local->port_category !== ($prod['port_category'] ?? null)) $diff[] = "port_category: '{$local->port_category}' vs '{$prod['port_category']}'";
            if ($local->iata_code !== ($prod['iata_code'] ?? null)) $diff[] = "iata_code: '{$local->iata_code}' vs '{$prod['iata_code']}'";
            if ($local->icao_code !== ($prod['icao_code'] ?? null)) $diff[] = "icao_code: '{$local->icao_code}' vs '{$prod['icao_code']}'";
            
            if (!empty($diff)) {
                $differences[$code] = $diff;
            }
        }

        if (!empty($differences)) {
            $this->warn('⚠️  Ports with differences (' . count($differences) . '):');
            foreach ($differences as $code => $diff) {
                $this->line("   - {$code}:");
                foreach ($diff as $d) {
                    $this->line("     • {$d}");
                }
            }
            $this->newLine();
        }

        // Summary
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('📊 SUMMARY');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info("Local ports: " . count($localCodes));
        $this->info("Production ports: " . count($productionCodes));
        $this->info("In both: " . count($inBoth));
        $this->info("Missing in production: " . count($missingInProduction));
        $this->info("Missing in local: " . count($missingInLocal));
        $this->info("With differences: " . count($differences));
        $this->newLine();

        if (!empty($missingInProduction) || !empty($differences)) {
            $this->warn('💡 RECOMMENDATION: Run migrations and seeders on production to sync ports');
        } else {
            $this->info('✅ Ports are synchronized!');
        }
    }
}

