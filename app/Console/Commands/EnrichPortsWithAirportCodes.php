<?php

namespace App\Console\Commands;

use App\Models\Port;
use App\Services\Ports\AirportDataProvider;
use Illuminate\Console\Command;

class EnrichPortsWithAirportCodes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ports:enrich-airports
                            {--path= : CSV/OpenFlights path}
                            {--dry-run : Do not write, only report}
                            {--force-update : Overwrite existing values}
                            {--limit= : Optional limit for testing}
                            {--allowlist=default : default|all}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enrich ports with IATA/ICAO airport codes using conservative high-confidence matching';

    /**
     * Statistics tracking
     */
    private array $stats = [
        'updated_count' => 0,
        'skipped_count' => 0,
        'ambiguous_count' => 0,
        'ambiguous' => [],
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->option('path');
        if (empty($path)) {
            $this->error('Please provide a CSV/OpenFlights file path using --path option');
            return Command::FAILURE;
        }

        $this->info('✈️  Starting airport code enrichment...');
        $this->newLine();

        // Load allowlist
        $allowlist = config('ports_allowlist', []);
        $allowlistMode = $this->option('allowlist');
        $allowedIata = [];

        if ($allowlistMode === 'default') {
            $allowedIata = $allowlist['iata'] ?? [];
            $this->info("Allowlist mode: default (filtering by IATA codes)");
            $this->line("   Allowed IATA codes: " . count($allowedIata));
        } else {
            $this->info("Allowlist mode: all (no filtering)");
        }
        $this->newLine();

        $provider = new AirportDataProvider();
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $processed = 0;

        try {
            foreach ($provider->readFromPath($path) as $airport) {
                // Apply limit for testing
                if ($limit !== null && $processed >= $limit) {
                    break;
                }

                // Apply allowlist filtering
                if ($allowlistMode === 'default' && !empty($allowedIata)) {
                    $iata = $airport['iata_code'] ?? '';
                    if (!empty($iata) && !in_array($iata, $allowedIata)) {
                        $this->stats['skipped_count']++;
                        continue;
                    }
                }

                // Process the airport
                $this->processAirport($airport);

                $processed++;
            }
        } catch (\Exception $e) {
            $this->error("Error processing airport data: " . $e->getMessage());
            return Command::FAILURE;
        }

        // Display results
        $this->displayResults();

        return Command::SUCCESS;
    }

    /**
     * Process a single airport record
     */
    private function processAirport(array $airport): void
    {
        $iata = $airport['iata_code'] ?? '';
        $icao = $airport['icao_code'] ?? '';

        // Priority 1: Match by existing IATA code
        if (!empty($iata)) {
            $port = Port::where('iata_code', $iata)->first();
            if ($port) {
                $this->updatePort($port, $airport);
                return;
            }
        }

        // Priority 2: Match by country_code + exact name/city (only if unique)
        if (!empty($iata) || !empty($icao)) {
            $port = $this->matchByCountryAndName($airport);
            if ($port) {
                $this->updatePort($port, $airport);
                return;
            }
        }

        // Priority 3: Match by ICAO only (if no IATA)
        if (empty($iata) && !empty($icao)) {
            $port = Port::where('icao_code', $icao)->first();
            if ($port) {
                $this->updatePort($port, $airport);
                return;
            }
        }

        $this->stats['skipped_count']++;
    }

    /**
     * Match airport by country_code + exact name/city (high confidence, unique only)
     */
    private function matchByCountryAndName(array $airport): ?Port
    {
        $country = $airport['country'] ?? '';
        $city = $airport['city'] ?? '';
        $name = $airport['name'] ?? '';

        // Try to get country_code from country name
        $countryCode = $this->getCountryCodeFromName($country);
        if (empty($countryCode)) {
            return null;
        }

        // Try matching by city name first (more specific)
        if (!empty($city)) {
            $matches = Port::where('country_code', $countryCode)
                ->whereRaw('UPPER(name) = ?', [strtoupper($city)])
                ->get();

            if ($matches->count() === 1) {
                return $matches->first();
            } elseif ($matches->count() > 1) {
                // Ambiguous
                $this->stats['ambiguous_count']++;
                $this->stats['ambiguous'][] = [
                    'type' => 'city',
                    'city' => $city,
                    'country' => $country,
                    'iata' => $airport['iata_code'] ?? '',
                    'existing_ports' => $matches->map(fn($p) => [
                        'id' => $p->id,
                        'code' => $p->code,
                        'name' => $p->name,
                    ])->toArray(),
                ];
                return null;
            }
        }

        // Try matching by airport name
        if (!empty($name)) {
            $matches = Port::where('country_code', $countryCode)
                ->whereRaw('UPPER(name) = ?', [strtoupper($name)])
                ->get();

            if ($matches->count() === 1) {
                return $matches->first();
            } elseif ($matches->count() > 1) {
                // Ambiguous
                $this->stats['ambiguous_count']++;
                $this->stats['ambiguous'][] = [
                    'type' => 'name',
                    'name' => $name,
                    'country' => $country,
                    'iata' => $airport['iata_code'] ?? '',
                    'existing_ports' => $matches->map(fn($p) => [
                        'id' => $p->id,
                        'code' => $p->code,
                        'name' => $p->name,
                    ])->toArray(),
                ];
                return null;
            }
        }

        return null;
    }

    /**
     * Get country code from country name (minimal mapping)
     */
    private function getCountryCodeFromName(string $countryName): ?string
    {
        if (empty($countryName)) {
            return null;
        }

        // Minimal country name to ISO2 mapping (curated for Belgaco lanes)
        $countryMap = [
            'Belgium' => 'BE',
            'Netherlands' => 'NL',
            'Germany' => 'DE',
            'France' => 'FR',
            'Spain' => 'ES',
            'United Kingdom' => 'GB',
            'UK' => 'GB',
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
        ];

        $normalized = trim($countryName);
        return $countryMap[$normalized] ?? null;
    }

    /**
     * Update port with airport data
     */
    private function updatePort(Port $port, array $airport): void
    {
        $updates = [];
        $forceUpdate = $this->option('force-update');

        // Set port_category to AIRPORT (only if UNKNOWN or force-update)
        if ($forceUpdate || ($port->port_category === 'UNKNOWN' || empty($port->port_category))) {
            $updates['port_category'] = 'AIRPORT';
        }

        // Set IATA code (only if empty or force-update)
        $iata = $airport['iata_code'] ?? '';
        if (!empty($iata)) {
            if ($forceUpdate || empty($port->iata_code)) {
                $updates['iata_code'] = $iata;
            }
        }

        // Set ICAO code (only if empty or force-update)
        $icao = $airport['icao_code'] ?? '';
        if (!empty($icao)) {
            if ($forceUpdate || empty($port->icao_code)) {
                $updates['icao_code'] = $icao;
            }
        }

        // Set coordinates (only if empty or force-update)
        $coordinates = $airport['coordinates'] ?? null;
        if (!empty($coordinates)) {
            if ($forceUpdate || empty($port->coordinates)) {
                $updates['coordinates'] = $coordinates;
            }
        }

        if (empty($updates)) {
            $this->stats['skipped_count']++;
            return;
        }

        if ($this->option('dry-run')) {
            $this->line("  [DRY RUN] Would update: {$port->name} ({$port->code})");
            $this->line("    Fields: " . implode(', ', array_keys($updates)));
            $this->stats['updated_count']++;
            return;
        }

        $port->update($updates);
        $this->line("  ✅ Updated: {$port->name} ({$port->code}) - IATA: {$iata}, ICAO: {$icao}");
        $this->stats['updated_count']++;
    }

    /**
     * Display results summary
     */
    private function displayResults(): void
    {
        $this->newLine();
        $this->info('📊 Enrichment Results:');
        $this->line("   Updated: {$this->stats['updated_count']}");
        $this->line("   Skipped: {$this->stats['skipped_count']}");
        $this->line("   Ambiguous: {$this->stats['ambiguous_count']}");

        // Show ambiguous matches
        if (!empty($this->stats['ambiguous'])) {
            $this->newLine();
            $this->warn('⚠️  Ambiguous matches (first 20):');
            $ambiguous = array_slice($this->stats['ambiguous'], 0, 20);
            foreach ($ambiguous as $amb) {
                $this->line("   - {$amb['type']}: {$amb[$amb['type']]} ({$amb['country']}) - IATA: {$amb['iata']}");
                $this->line("     Multiple existing ports found, skipping");
            }
        }
    }
}

