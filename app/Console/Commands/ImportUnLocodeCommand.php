<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Port;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImportUnLocodeCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ports:import-unlocode 
                            {--source=manual : Source to import from (manual|api|file)}
                            {--file= : Path to CSV file if using file source}
                            {--update-existing : Update existing ports with new data}
                            {--dry-run : Show what would be imported without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Import port codes from UN/LOCODE database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $source = $this->option('source');
        $updateExisting = $this->option('update-existing');
        $dryRun = $this->option('dry-run');

        $this->info("🚢 Importing UN/LOCODE data from: {$source}");
        $this->newLine();

        try {
            switch ($source) {
                case 'manual':
                    $data = $this->getManualUnlocodeData();
                    break;
                case 'api':
                    $data = $this->fetchFromApi();
                    break;
                case 'file':
                    $filePath = $this->option('file');
                    if (!$filePath) {
                        $this->error('❌ File path required when using file source');
                        return 1;
                    }
                    $data = $this->parseCsvFile($filePath);
                    break;
                default:
                    $this->error("❌ Unknown source: {$source}");
                    return 1;
            }

            if (empty($data)) {
                $this->error('❌ No data retrieved from source');
                return 1;
            }

            $this->info("📋 Retrieved " . count($data) . " port records");
            $this->newLine();

            if ($dryRun) {
                $this->displayDryRun($data);
                return 0;
            }

            $this->importPorts($data, $updateExisting);

        } catch (\Exception $e) {
            $this->error("❌ Error importing UN/LOCODE data: " . $e->getMessage());
            Log::error('UN/LOCODE import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }

        return 0;
    }

    /**
     * Get manually curated UN/LOCODE data
     * This is verified data from official UN/LOCODE database
     */
    private function getManualUnlocodeData(): array
    {
        return [
            // West Africa
            [
                'code' => 'ABJ',
                'name' => 'Abidjan',
                'country' => 'Côte d\'Ivoire',
                'region' => 'West Africa',
                'unlocode' => 'CI ABJ',
                'coordinates' => '5.3167,-4.0333',
                'type' => 'pod'
            ],
            [
                'code' => 'CKY',
                'name' => 'Conakry',
                'country' => 'Guinea',
                'region' => 'West Africa',
                'unlocode' => 'GN CKY',
                'coordinates' => '9.6412,-13.5784',
                'type' => 'pod'
            ],
            [
                'code' => 'COO',
                'name' => 'Cotonou',
                'country' => 'Benin',
                'region' => 'West Africa',
                'unlocode' => 'BJ COO',
                'coordinates' => '6.3667,2.4333',
                'type' => 'pod'
            ],
            [
                'code' => 'DKR',
                'name' => 'Dakar',
                'country' => 'Senegal',
                'region' => 'West Africa',
                'unlocode' => 'SN DKR',
                'coordinates' => '14.6928,-17.4467',
                'type' => 'pod'
            ],
            [
                'code' => 'DLA',
                'name' => 'Douala',
                'country' => 'Cameroon',
                'region' => 'West Africa',
                'unlocode' => 'CM DLA',
                'coordinates' => '4.0500,9.7000',
                'type' => 'pod'
            ],
            [
                'code' => 'LOS',
                'name' => 'Lagos',
                'country' => 'Nigeria',
                'region' => 'West Africa',
                'unlocode' => 'NG LOS',
                'coordinates' => '6.5244,3.3792',
                'type' => 'pod'
            ],
            [
                'code' => 'LFW',
                'name' => 'Lomé',
                'country' => 'Togo',
                'region' => 'West Africa',
                'unlocode' => 'TG LFW',
                'coordinates' => '6.1319,1.2228',
                'type' => 'pod'
            ],
            [
                'code' => 'PNR',
                'name' => 'Pointe Noire',
                'country' => 'Republic of Congo',
                'region' => 'West Africa',
                'unlocode' => 'CG PNR',
                'coordinates' => '-4.7761,11.8636',
                'type' => 'pod'
            ],
            
            // East Africa
            [
                'code' => 'DAR',
                'name' => 'Dar es Salaam',
                'country' => 'Tanzania',
                'region' => 'East Africa',
                'unlocode' => 'TZ DAR',
                'coordinates' => '-6.7924,39.2083',
                'type' => 'pod'
            ],
            [
                'code' => 'MBA',
                'name' => 'Mombasa',
                'country' => 'Kenya',
                'region' => 'East Africa',
                'unlocode' => 'KE MBA',
                'coordinates' => '-4.0437,39.6682',
                'type' => 'pod'
            ],
            
            // South Africa
            [
                'code' => 'DUR',
                'name' => 'Durban',
                'country' => 'South Africa',
                'region' => 'South Africa',
                'unlocode' => 'ZA DUR',
                'coordinates' => '-29.8587,31.0218',
                'type' => 'pod'
            ],
            [
                'code' => 'ELS',
                'name' => 'East London',
                'country' => 'South Africa',
                'region' => 'South Africa',
                'unlocode' => 'ZA ELS',
                'coordinates' => '-33.0292,27.8546',
                'type' => 'pod'
            ],
            [
                'code' => 'PLZ',
                'name' => 'Port Elizabeth',
                'country' => 'South Africa',
                'region' => 'South Africa',
                'unlocode' => 'ZA PLZ',
                'coordinates' => '-33.9608,25.6022',
                'type' => 'pod'
            ],
            [
                'code' => 'WVB',
                'name' => 'Walvis Bay',
                'country' => 'Namibia',
                'region' => 'South Africa',
                'unlocode' => 'NA WVB',
                'coordinates' => '-22.9576,14.5053',
                'type' => 'pod'
            ],
            
            // Europe (POLs)
            [
                'code' => 'ANR',
                'name' => 'Antwerp',
                'country' => 'Belgium',
                'region' => 'Europe',
                'unlocode' => 'BE ANR',
                'coordinates' => '51.2194,4.4025',
                'type' => 'pol'
            ],
            [
                'code' => 'ZEE',
                'name' => 'Zeebrugge',
                'country' => 'Belgium',
                'region' => 'Europe',
                'unlocode' => 'BE ZEE',
                'coordinates' => '51.3308,3.2075',
                'type' => 'pol'
            ],
            [
                'code' => 'FLU',
                'name' => 'Vlissingen',
                'country' => 'Netherlands',
                'region' => 'Europe',
                'unlocode' => 'NL VLI',
                'coordinates' => '51.4425,3.5736',
                'type' => 'pol'
            ],
        ];
    }

    /**
     * Fetch data from UN/LOCODE API (if available)
     */
    private function fetchFromApi(): array
    {
        $this->warn('⚠️  UN/LOCODE API is not publicly available.');
        $this->info('Using manual curated data instead...');
        
        return $this->getManualUnlocodeData();
    }

    /**
     * Parse CSV file with UN/LOCODE data
     */
    private function parseCsvFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \Exception("File not found: {$filePath}");
        }

        $data = [];
        $handle = fopen($filePath, 'r');
        
        if (!$handle) {
            throw new \Exception("Cannot open file: {$filePath}");
        }

        // Skip header row
        fgetcsv($handle);

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 5) {
                $data[] = [
                    'code' => strtoupper(trim($row[0])),
                    'name' => trim($row[1]),
                    'country' => trim($row[2]),
                    'region' => trim($row[3]) ?: 'Unknown',
                    'unlocode' => trim($row[4]),
                    'coordinates' => isset($row[5]) ? trim($row[5]) : null,
                    'type' => isset($row[6]) ? trim($row[6]) : 'both'
                ];
            }
        }

        fclose($handle);
        return $data;
    }

    /**
     * Display dry run results
     */
    private function displayDryRun(array $data): void
    {
        $this->info('🔍 Dry Run - What would be imported:');
        $this->newLine();

        $existingPorts = Port::pluck('code')->toArray();
        $newPorts = [];
        $updatedPorts = [];

        foreach ($data as $portData) {
            if (in_array($portData['code'], $existingPorts)) {
                $updatedPorts[] = $portData;
            } else {
                $newPorts[] = $portData;
            }
        }

        if (!empty($newPorts)) {
            $this->info("➕ New Ports (" . count($newPorts) . "):");
            foreach ($newPorts as $port) {
                $this->line("   {$port['code']} - {$port['name']} ({$port['country']})");
            }
            $this->newLine();
        }

        if (!empty($updatedPorts)) {
            $this->info("🔄 Updated Ports (" . count($updatedPorts) . "):");
            foreach ($updatedPorts as $port) {
                $this->line("   {$port['code']} - {$port['name']} ({$port['country']})");
            }
            $this->newLine();
        }

        $this->info("📊 Summary:");
        $this->line("   Total records: " . count($data));
        $this->line("   New ports: " . count($newPorts));
        $this->line("   Updated ports: " . count($updatedPorts));
    }

    /**
     * Import ports to database
     */
    private function importPorts(array $data, bool $updateExisting): void
    {
        $imported = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($data as $portData) {
            $existingPort = Port::where('code', $portData['code'])->first();

            if ($existingPort) {
                if ($updateExisting) {
                    $existingPort->update([
                        'name' => $portData['name'],
                        'country' => $portData['country'],
                        'region' => $portData['region'],
                        'coordinates' => $portData['coordinates'],
                        'type' => $portData['type'],
                        'is_active' => true
                    ]);
                    $updated++;
                    $this->line("🔄 Updated: {$portData['code']} - {$portData['name']}");
                } else {
                    $skipped++;
                    $this->line("⏭️  Skipped: {$portData['code']} - {$portData['name']} (exists)");
                }
            } else {
                Port::create([
                    'code' => $portData['code'],
                    'name' => $portData['name'],
                    'country' => $portData['country'],
                    'region' => $portData['region'],
                    'coordinates' => $portData['coordinates'],
                    'type' => $portData['type'],
                    'is_active' => true
                ]);
                $imported++;
                $this->line("➕ Imported: {$portData['code']} - {$portData['name']}");
            }
        }

        $this->newLine();
        $this->info("✅ Import completed:");
        $this->line("   Imported: {$imported}");
        $this->line("   Updated: {$updated}");
        $this->line("   Skipped: {$skipped}");
    }
}
