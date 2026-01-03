<?php

namespace App\Console\Commands;

use App\Models\Port;
use Illuminate\Console\Command;

class BackfillPortCountryCodes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ports:backfill-country-codes
                            {--dry-run : Do not write, only report}
                            {--force-update : Overwrite existing country_code values}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill missing country_code fields for legacy/manual ports';

    /**
     * Statistics tracking
     */
    private array $stats = [
        'updated_count' => 0,
        'skipped_count' => 0,
        'uncertain_count' => 0,
        'uncertain' => [],
    ];

    /**
     * Minimal country name to ISO2 mapping (curated for Belgaco lanes)
     */
    private array $countryNameMap = [
        'Belgium' => 'BE',
        'Netherlands' => 'NL',
        'Germany' => 'DE',
        'France' => 'FR',
        'Spain' => 'ES',
        'United Kingdom' => 'GB',
        'UK' => 'GB',
        'Great Britain' => 'GB',
        'Italy' => 'IT',
        'Portugal' => 'PT',
        'United Arab Emirates' => 'AE',
        'UAE' => 'AE',
        'Saudi Arabia' => 'SA',
        'Qatar' => 'QA',
        'Kuwait' => 'KW',
        'Oman' => 'OM',
        'Bahrain' => 'BH',
        'Côte d\'Ivoire' => 'CI',
        'Ivory Coast' => 'CI',
        'Cote d\'Ivoire' => 'CI',
        'Ghana' => 'GH',
        'Nigeria' => 'NG',
        'Senegal' => 'SN',
        'Gambia' => 'GM',
        'Guinea' => 'GN',
        'Sierra Leone' => 'SL',
        'Liberia' => 'LR',
        'Togo' => 'TG',
        'Benin' => 'BJ',
        'Cameroon' => 'CM',
        'Gabon' => 'GA',
        'Angola' => 'AO',
        'Morocco' => 'MA',
        'Tunisia' => 'TN',
        'Libya' => 'LY',
        'Egypt' => 'EG',
        'Kenya' => 'KE',
        'Tanzania' => 'TZ',
        'South Africa' => 'ZA',
        'Namibia' => 'NA',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🌍 Starting country code backfill...');
        $this->newLine();

        // Find ports with missing country_code
        $query = Port::whereNull('country_code')
            ->orWhere('country_code', '')
            ->orWhere('country_code', '=', '');

        $ports = $query->get();
        $this->info("Found {$ports->count()} ports with missing country_code");
        $this->newLine();

        foreach ($ports as $port) {
            $this->processPort($port);
        }

        // Display results
        $this->displayResults();

        return Command::SUCCESS;
    }

    /**
     * Process a single port
     */
    private function processPort(Port $port): void
    {
        $countryCode = null;

        // Priority 1: Extract from unlocode (first 2 characters)
        if (!empty($port->unlocode) && strlen($port->unlocode) >= 2) {
            $potentialCode = strtoupper(substr($port->unlocode, 0, 2));
            // Validate: should be 2 letters
            if (ctype_alpha($potentialCode) && strlen($potentialCode) === 2) {
                $countryCode = $potentialCode;
            }
        }

        // Priority 2: Try country name mapping
        if (empty($countryCode) && !empty($port->country)) {
            $countryCode = $this->getCountryCodeFromName($port->country);
        }

        // If still uncertain, report it
        if (empty($countryCode)) {
            $this->stats['uncertain_count']++;
            $this->stats['uncertain'][] = [
                'id' => $port->id,
                'code' => $port->code,
                'name' => $port->name,
                'country' => $port->country,
                'unlocode' => $port->unlocode,
            ];
            $this->stats['skipped_count']++;
            return;
        }

        // Check if update is needed
        $forceUpdate = $this->option('force-update');
        if (!$forceUpdate && !empty($port->country_code)) {
            $this->stats['skipped_count']++;
            return;
        }

        if ($this->option('dry-run')) {
            $this->line("  [DRY RUN] Would set country_code: {$port->name} ({$port->code}) -> {$countryCode}");
            $this->stats['updated_count']++;
            return;
        }

        $port->update(['country_code' => $countryCode]);
        $this->line("  ✅ Updated: {$port->name} ({$port->code}) -> {$countryCode}");
        $this->stats['updated_count']++;
    }

    /**
     * Get country code from country name
     */
    private function getCountryCodeFromName(string $countryName): ?string
    {
        if (empty($countryName)) {
            return null;
        }

        $normalized = trim($countryName);
        
        // Direct match
        if (isset($this->countryNameMap[$normalized])) {
            return $this->countryNameMap[$normalized];
        }

        // Case-insensitive match
        foreach ($this->countryNameMap as $name => $code) {
            if (strcasecmp($normalized, $name) === 0) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Display results summary
     */
    private function displayResults(): void
    {
        $this->newLine();
        $this->info('📊 Backfill Results:');
        $this->line("   Updated: {$this->stats['updated_count']}");
        $this->line("   Skipped: {$this->stats['skipped_count']}");
        $this->line("   Uncertain: {$this->stats['uncertain_count']}");

        // Show uncertain ports
        if (!empty($this->stats['uncertain'])) {
            $this->newLine();
            $this->warn('⚠️  Ports with uncertain country_code (first 20):');
            $uncertain = array_slice($this->stats['uncertain'], 0, 20);
            foreach ($uncertain as $port) {
                $this->line("   - {$port['name']} ({$port['code']}) - Country: {$port['country']}, UN/LOCODE: {$port['unlocode']}");
            }
        }
    }
}

