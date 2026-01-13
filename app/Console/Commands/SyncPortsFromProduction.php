<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Port;

class SyncPortsFromProduction extends Command
{
    protected $signature = 'ports:sync-from-production 
                            {--dry-run : Show what would be created/updated without actually making changes}
                            {--force : Update existing ports with production data}';

    protected $description = 'Import missing ports from production database';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('🔄 Syncing ports from production...');
        $this->newLine();

        // Fetch production ports via SSH
        $this->info('📊 Fetching production ports...');
        $productionPortsJson = shell_exec("ssh forge@bconnect.64.226.120.45.nip.io 'cd app.belgaco.be && php artisan tinker --execute=\"echo json_encode(\\\\App\\\\Models\\\\Port::orderBy(\\\"code\\\")->get([\\\"code\\\", \\\"name\\\", \\\"country\\\", \\\"region\\\", \\\"type\\\", \\\"is_active\\\", \\\"unlocode\\\", \\\"country_code\\\", \\\"port_category\\\", \\\"iata_code\\\", \\\"icao_code\\\"])->toArray());\"'");

        if (!$productionPortsJson) {
            $this->error('❌ Failed to fetch production ports');
            return 1;
        }

        $productionPorts = json_decode($productionPortsJson, true);
        if (!$productionPorts) {
            $this->error('❌ Failed to parse production ports JSON');
            return 1;
        }

        $this->info("Found " . count($productionPorts) . " ports in production");
        $this->newLine();

        // Get local ports
        $localPorts = Port::all()->keyBy('code');
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($productionPorts as $prodPort) {
            $code = $prodPort['code'];
            $existing = $localPorts->get($code);

            if (!$existing) {
                // Port doesn't exist locally - create it
                $this->line("  ✨ Creating: {$code} - {$prodPort['name']}" . 
                           ($prodPort['country'] ? " ({$prodPort['country']})" : ''));
                
                if (!$dryRun) {
                    Port::create([
                        'code' => $code,
                        'name' => $prodPort['name'],
                        'country' => $prodPort['country'] ?? null,
                        'region' => $prodPort['region'] ?? null,
                        'type' => $prodPort['type'] ?? 'both',
                        'is_active' => $prodPort['is_active'] ?? true,
                        'unlocode' => $prodPort['unlocode'] ?? null,
                        'country_code' => $prodPort['country_code'] ?? null,
                        'port_category' => $prodPort['port_category'] ?? 'UNKNOWN',
                        'iata_code' => $prodPort['iata_code'] ?? null,
                        'icao_code' => $prodPort['icao_code'] ?? null,
                    ]);
                }
                $created++;
            } else {
                // Port exists - check if we should update
                $needsUpdate = false;
                $updates = [];

                $fieldsToCheck = [
                    'unlocode', 'country_code', 'port_category', 
                    'iata_code', 'icao_code', 'type', 'region'
                ];

                foreach ($fieldsToCheck as $field) {
                    $localValue = $existing->$field ?? '';
                    $prodValue = $prodPort[$field] ?? null;
                    
                    // Normalize empty strings to null for comparison
                    if ($localValue === '') {
                        $localValue = null;
                    }
                    
                    if ($localValue !== $prodValue && $prodValue !== null) {
                        $needsUpdate = true;
                        $updates[$field] = $prodValue;
                    }
                }

                if ($needsUpdate) {
                    if ($force) {
                        $this->line("  🔄 Updating: {$code} - {$prodPort['name']}");
                        foreach ($updates as $field => $value) {
                            $this->line("     • {$field}: '{$existing->$field}' → '{$value}'");
                        }
                        
                        if (!$dryRun) {
                            $existing->update($updates);
                        }
                        $updated++;
                    } else {
                        $this->line("  ⏭️  Skipping (exists, use --force to update): {$code} - {$prodPort['name']}");
                        $skipped++;
                    }
                } else {
                    $this->line("  ✓ Already synced: {$code} - {$prodPort['name']}");
                    $skipped++;
                }
            }
        }

        $this->newLine();
        
        if ($dryRun) {
            $this->info("📊 Summary (DRY RUN):");
            $this->info("  - Would create: {$created} ports");
            $this->info("  - Would update: {$updated} ports");
            $this->info("  - Would skip: {$skipped} ports");
            $this->newLine();
            $this->comment('Run without --dry-run to actually create/update ports.');
        } else {
            $this->info("✅ Summary:");
            $this->info("  - Created: {$created} ports");
            $this->info("  - Updated: {$updated} ports");
            $this->info("  - Skipped: {$skipped} ports");
            $this->newLine();
            $this->info("Total ports in database: " . Port::count());
        }

        return 0;
    }
}
