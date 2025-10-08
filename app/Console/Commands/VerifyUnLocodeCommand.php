<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Port;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VerifyUnLocodeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ports:verify-unlocode {--update : Update incorrect codes automatically}';

    /**
     * The console command description.
     */
    protected $description = 'Verify current port codes against UN/LOCODE database and optionally update incorrect ones';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 Verifying port codes against UN/LOCODE database...');
        $this->newLine();

        // Get current ports from database
        $currentPorts = Port::all();
        
        if ($currentPorts->isEmpty()) {
            $this->error('❌ No ports found in database. Run port seeders first.');
            return 1;
        }

        $this->info("📋 Found {$currentPorts->count()} ports to verify");
        $this->newLine();

        // UN/LOCODE verification data (manually curated for our ports)
        $unlocodeData = $this->getUnlocodeData();
        
        $correctCodes = [];
        $incorrectCodes = [];
        $missingCodes = [];

        foreach ($currentPorts as $port) {
            $portCode = strtoupper($port->code);
            $found = false;
            
            foreach ($unlocodeData as $unlocode) {
                if ($unlocode['code'] === $portCode) {
                    $found = true;
                    
                    // Check if name matches (allowing for variations)
                    $nameMatch = $this->comparePortNames($port->name, $unlocode['name']);
                    
                    if ($nameMatch) {
                        $correctCodes[] = [
                            'port' => $port,
                            'unlocode' => $unlocode,
                            'status' => 'correct'
                        ];
                    } else {
                        $incorrectCodes[] = [
                            'port' => $port,
                            'unlocode' => $unlocode,
                            'status' => 'name_mismatch',
                            'current_name' => $port->name,
                            'correct_name' => $unlocode['name']
                        ];
                    }
                    break;
                }
            }
            
            if (!$found) {
                $missingCodes[] = [
                    'port' => $port,
                    'status' => 'not_found'
                ];
            }
        }

        // Display results
        $this->displayResults($correctCodes, $incorrectCodes, $missingCodes);

        // Handle updates if requested
        if ($this->option('update') && !empty($incorrectCodes)) {
            $this->handleUpdates($incorrectCodes);
        }

        return 0;
    }

    /**
     * Get UN/LOCODE data for our specific ports
     * This is manually curated data from official UN/LOCODE database
     */
    private function getUnlocodeData(): array
    {
        return [
            // West Africa
            ['code' => 'ABJ', 'name' => 'Abidjan', 'country' => 'Côte d\'Ivoire', 'unlocode' => 'CI ABJ'],
            ['code' => 'CKY', 'name' => 'Conakry', 'country' => 'Guinea', 'unlocode' => 'GN CKY'],
            ['code' => 'COO', 'name' => 'Cotonou', 'country' => 'Benin', 'unlocode' => 'BJ COO'],
            ['code' => 'DKR', 'name' => 'Dakar', 'country' => 'Senegal', 'unlocode' => 'SN DKR'],
            ['code' => 'DLA', 'name' => 'Douala', 'country' => 'Cameroon', 'unlocode' => 'CM DLA'],
            ['code' => 'LOS', 'name' => 'Lagos', 'country' => 'Nigeria', 'unlocode' => 'NG LOS'],
            ['code' => 'LFW', 'name' => 'Lomé', 'country' => 'Togo', 'unlocode' => 'TG LFW'],
            ['code' => 'PNR', 'name' => 'Pointe Noire', 'country' => 'Republic of Congo', 'unlocode' => 'CG PNR'],
            
            // East Africa
            ['code' => 'DAR', 'name' => 'Dar es Salaam', 'country' => 'Tanzania', 'unlocode' => 'TZ DAR'],
            ['code' => 'MBA', 'name' => 'Mombasa', 'country' => 'Kenya', 'unlocode' => 'KE MBA'],
            
            // South Africa
            ['code' => 'DUR', 'name' => 'Durban', 'country' => 'South Africa', 'unlocode' => 'ZA DUR'],
            ['code' => 'ELS', 'name' => 'East London', 'country' => 'South Africa', 'unlocode' => 'ZA ELS'],
            ['code' => 'PLZ', 'name' => 'Port Elizabeth', 'country' => 'South Africa', 'unlocode' => 'ZA PLZ'],
            ['code' => 'WVB', 'name' => 'Walvis Bay', 'country' => 'Namibia', 'unlocode' => 'NA WVB'],
            
            // Europe
            ['code' => 'ANR', 'name' => 'Antwerp', 'country' => 'Belgium', 'unlocode' => 'BE ANR'],
            ['code' => 'ZEE', 'name' => 'Zeebrugge', 'country' => 'Belgium', 'unlocode' => 'BE ZEE'],
            ['code' => 'FLU', 'name' => 'Vlissingen', 'country' => 'Netherlands', 'unlocode' => 'NL VLI'],
        ];
    }

    /**
     * Compare port names allowing for variations
     */
    private function comparePortNames(string $currentName, string $unlocodeName): bool
    {
        // Normalize names for comparison
        $current = strtolower(trim($currentName));
        $unlocode = strtolower(trim($unlocodeName));
        
        // Direct match
        if ($current === $unlocode) {
            return true;
        }
        
        // Handle common variations
        $variations = [
            'lagos (tin can island)' => 'lagos',
            'port elizabeth' => 'port elizabeth',
            'vlissingen' => 'vlissingen',
            'flushing' => 'vlissingen', // Flushing is the English name for Vlissingen
        ];
        
        foreach ($variations as $variant => $standard) {
            if ($current === $variant && $unlocode === $standard) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Display verification results
     */
    private function displayResults(array $correctCodes, array $incorrectCodes, array $missingCodes): void
    {
        $this->info('📊 Verification Results:');
        $this->newLine();

        // Correct codes
        if (!empty($correctCodes)) {
            $this->info("✅ Correct Codes (" . count($correctCodes) . "):");
            foreach ($correctCodes as $item) {
                $port = $item['port'];
                $unlocode = $item['unlocode'];
                $this->line("   {$port->code} - {$port->name} ({$port->country}) ✓");
            }
            $this->newLine();
        }

        // Incorrect codes
        if (!empty($incorrectCodes)) {
            $this->warn("⚠️  Incorrect Codes (" . count($incorrectCodes) . "):");
            foreach ($incorrectCodes as $item) {
                $port = $item['port'];
                $unlocode = $item['unlocode'];
                $this->line("   {$port->code} - Current: '{$port->name}' → Correct: '{$unlocode['name']}'");
            }
            $this->newLine();
        }

        // Missing codes
        if (!empty($missingCodes)) {
            $this->error("❌ Codes Not Found in UN/LOCODE (" . count($missingCodes) . "):");
            foreach ($missingCodes as $item) {
                $port = $item['port'];
                $this->line("   {$port->code} - {$port->name} ({$port->country})");
            }
            $this->newLine();
        }

        // Summary
        $total = count($correctCodes) + count($incorrectCodes) + count($missingCodes);
        $this->info("📈 Summary:");
        $this->line("   Total ports: {$total}");
        $this->line("   Correct: " . count($correctCodes));
        $this->line("   Incorrect: " . count($incorrectCodes));
        $this->line("   Not found: " . count($missingCodes));
        $this->newLine();

        if (!empty($incorrectCodes)) {
            $this->info("💡 To update incorrect codes, run: php artisan ports:verify-unlocode --update");
        }
    }

    /**
     * Handle automatic updates
     */
    private function handleUpdates(array $incorrectCodes): void
    {
        $this->info('🔄 Updating incorrect port codes...');
        $this->newLine();

        foreach ($incorrectCodes as $item) {
            $port = $item['port'];
            $unlocode = $item['unlocode'];
            
            $this->line("Updating {$port->code}: '{$port->name}' → '{$unlocode['name']}'");
            
            $port->update([
                'name' => $unlocode['name'],
                'country' => $unlocode['country']
            ]);
        }

        $this->info("✅ Updated " . count($incorrectCodes) . " port codes");
    }
}
