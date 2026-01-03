<?php

namespace App\Console\Commands;

use App\Models\Port;
use App\Services\Ports\UnlocodeDataProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncPortsFromUnlocode extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ports:sync-unlocode
                            {--paths=* : One or more CSV paths}
                            {--dry-run : Do not write, only report}
                            {--create : Create missing ports (default: true)}
                            {--update-missing : Only fill missing fields (default: true)}
                            {--force-update : Overwrite existing values from UN/LOCODE (default: false)}
                            {--limit= : Optional limit for testing}
                            {--countries=* : Optional ISO2 country filter (e.g. BE, FR, AE)}
                            {--allowlist=default : default|all|countries|unlocodes}
                            {--only-major : DEPRECATED: alias for --allowlist=unlocodes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import/sync ports from UN/LOCODE CSV files with idempotent matching and allowlist filtering';

    /**
     * Statistics tracking
     */
    private array $stats = [
        'created_count' => 0,
        'updated_count' => 0,
        'filled_missing_count' => 0,
        'skipped_count' => 0,
        'conflicts_count' => 0,
        'ambiguous_count' => 0,
        'conflicts' => [],
        'ambiguous' => [],
        'countries_processed' => [],
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $paths = $this->option('paths');
        if (empty($paths)) {
            $this->error('Please provide at least one CSV path using --paths option');
            return Command::FAILURE;
        }

        $this->info('🚢 Starting UN/LOCODE port sync...');
        $this->newLine();

        // Handle deprecated --only-major flag
        $allowlistMode = $this->option('only-major') ? 'unlocodes' : $this->option('allowlist');

        // Load allowlist configuration
        $allowlist = config('ports_allowlist', []);
        $allowedCountries = $this->getAllowedCountries($allowlist, $allowlistMode);
        $allowedUnlocodes = $this->getAllowedUnlocodes($allowlist, $allowlistMode);

        $this->info("Allowlist mode: {$allowlistMode}");
        if ($allowlistMode !== 'all') {
            if (!empty($allowedCountries)) {
                $this->line("   Countries: " . count($allowedCountries) . " allowed");
            }
            if (!empty($allowedUnlocodes)) {
                $this->line("   UN/LOCODEs: " . count($allowedUnlocodes) . " allowed");
            }
        }
        $this->newLine();

        $provider = new UnlocodeDataProvider();
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $processed = 0;

        try {
            foreach ($provider->readFromPaths($paths) as $row) {
                // Apply limit for testing
                if ($limit !== null && $processed >= $limit) {
                    break;
                }

                // Apply allowlist filtering
                if (!$this->shouldProcessRow($row, $allowlistMode, $allowedCountries, $allowedUnlocodes)) {
                    $this->stats['skipped_count']++;
                    continue;
                }

                // Apply explicit country filter if provided
                $explicitCountries = $this->option('countries');
                if (!empty($explicitCountries)) {
                    if (!in_array($row['country_code'], $explicitCountries)) {
                        $this->stats['skipped_count']++;
                        continue;
                    }
                }

                // Track countries processed
                if (!in_array($row['country_code'], $this->stats['countries_processed'])) {
                    $this->stats['countries_processed'][] = $row['country_code'];
                }

                // Process the row
                $this->processRow($row);

                $processed++;
            }
        } catch (\Exception $e) {
            $this->error("Error processing UN/LOCODE data: " . $e->getMessage());
            return Command::FAILURE;
        }

        // Display results
        $this->displayResults();

        return Command::SUCCESS;
    }

    /**
     * Get allowed countries based on allowlist mode
     */
    private function getAllowedCountries(array $allowlist, string $mode): array
    {
        if ($mode === 'all') {
            return [];
        }

        if ($mode === 'countries' || ($mode === 'default' && ($allowlist['default_mode'] ?? 'countries') === 'countries')) {
            return $allowlist['countries'] ?? [];
        }

        return [];
    }

    /**
     * Get allowed UN/LOCODEs based on allowlist mode
     */
    private function getAllowedUnlocodes(array $allowlist, string $mode): array
    {
        if ($mode === 'all') {
            return [];
        }

        if ($mode === 'unlocodes' || ($mode === 'default' && ($allowlist['default_mode'] ?? 'countries') === 'unlocodes')) {
            return $allowlist['unlocodes'] ?? [];
        }

        return [];
    }

    /**
     * Check if a row should be processed based on allowlist
     */
    private function shouldProcessRow(array $row, string $mode, array $allowedCountries, array $allowedUnlocodes): bool
    {
        if ($mode === 'all') {
            return true;
        }

        if ($mode === 'countries' || ($mode === 'default' && !empty($allowedCountries))) {
            return in_array($row['country_code'], $allowedCountries);
        }

        if ($mode === 'unlocodes' || ($mode === 'default' && !empty($allowedUnlocodes))) {
            return in_array($row['unlocode'], $allowedUnlocodes);
        }

        return true;
    }

    /**
     * Process a single UN/LOCODE row
     */
    private function processRow(array $row): void
    {
        $unlocode = $row['unlocode'];
        $countryCode = $row['country_code'];
        $locationCode = $row['location_code'];

        // Find existing port using matching strategy
        $port = $this->findExistingPort($row);

        if ($port === null) {
            // Create new port if allowed
            if ($this->option('create') !== false) { // default true
                $this->createPort($row);
            } else {
                $this->stats['skipped_count']++;
            }
            return;
        }

        // Update existing port
        $this->updatePort($port, $row);
    }

    /**
     * Find existing port using safe matching strategy
     */
    private function findExistingPort(array $row): ?Port
    {
        $unlocode = $row['unlocode'];
        $countryCode = $row['country_code'];
        $locationCode = $row['location_code'];
        $name = $row['name'];

        // Priority 1: Match by unlocode
        $port = Port::where('unlocode', $unlocode)->first();
        if ($port) {
            return $port;
        }

        // Priority 2: Match by country_code + code (case-insensitive)
        $port = Port::where('country_code', $countryCode)
            ->whereRaw('UPPER(code) = ?', [strtoupper($locationCode)])
            ->first();
        if ($port) {
            // Check for conflict: different unlocode
            if (!empty($port->unlocode) && $port->unlocode !== $unlocode) {
                $this->stats['conflicts_count']++;
                $this->stats['conflicts'][] = [
                    'matched_by' => 'country_code+code',
                    'existing' => [
                        'id' => $port->id,
                        'code' => $port->code,
                        'unlocode' => $port->unlocode,
                        'name' => $port->name,
                    ],
                    'new' => [
                        'unlocode' => $unlocode,
                        'code' => $locationCode,
                        'name' => $name,
                    ],
                ];
                return null; // Skip to avoid overwriting
            }
            return $port;
        }

        // Priority 3: Exact name match + same country_code (only if unique)
        if (!empty($name) && !empty($countryCode)) {
            $matches = Port::whereRaw('UPPER(name) = ?', [strtoupper($name)])
                ->where('country_code', $countryCode)
                ->get();

            if ($matches->count() === 1) {
                return $matches->first();
            } elseif ($matches->count() > 1) {
                // Ambiguous: multiple ports with same name in same country
                $this->stats['ambiguous_count']++;
                $this->stats['ambiguous'][] = [
                    'name' => $name,
                    'country_code' => $countryCode,
                    'unlocode' => $unlocode,
                    'existing_ports' => $matches->map(fn($p) => [
                        'id' => $p->id,
                        'code' => $p->code,
                        'unlocode' => $p->unlocode,
                    ])->toArray(),
                ];
                return null;
            }
        }

        return null;
    }

    /**
     * Create a new port from UN/LOCODE data
     */
    private function createPort(array $row): void
    {
        if ($this->option('dry-run')) {
            $this->line("  [DRY RUN] Would create: {$row['name']} ({$row['unlocode']})");
            $this->stats['created_count']++;
            return;
        }

        $port = Port::create([
            'name' => $row['name'],
            'code' => $row['location_code'],
            'country_code' => $row['country_code'],
            'unlocode' => $row['unlocode'],
            'coordinates' => $row['coordinates'],
            'port_category' => $row['port_category'],
            'is_active' => true,
        ]);

        $this->line("  ✅ Created: {$port->name} ({$port->code}) - {$port->unlocode}");
        $this->stats['created_count']++;
    }

    /**
     * Update an existing port from UN/LOCODE data
     */
    private function updatePort(Port $port, array $row): void
    {
        $updates = [];
        $forceUpdate = $this->option('force-update');
        $updateMissing = $this->option('update-missing') !== false; // default true

        // country_code
        if ($forceUpdate || ($updateMissing && empty($port->country_code))) {
            $updates['country_code'] = $row['country_code'];
        }

        // unlocode
        if ($forceUpdate || ($updateMissing && empty($port->unlocode))) {
            $updates['unlocode'] = $row['unlocode'];
        }

        // code (location code)
        if ($forceUpdate || ($updateMissing && empty($port->code))) {
            $updates['code'] = $row['location_code'];
        }

        // name
        if ($forceUpdate || ($updateMissing && empty($port->name))) {
            $updates['name'] = $row['name'];
        }

        // coordinates
        if ($forceUpdate || ($updateMissing && empty($port->coordinates))) {
            if (!empty($row['coordinates'])) {
                $updates['coordinates'] = $row['coordinates'];
            }
        }

        // port_category
        if ($forceUpdate || ($updateMissing && ($port->port_category === 'UNKNOWN' || empty($port->port_category)))) {
            $updates['port_category'] = $row['port_category'];
        }

        if (empty($updates)) {
            $this->stats['skipped_count']++;
            return;
        }

        if ($this->option('dry-run')) {
            $this->line("  [DRY RUN] Would update: {$port->name} ({$port->code})");
            $this->line("    Fields: " . implode(', ', array_keys($updates)));
            if ($forceUpdate) {
                $this->stats['updated_count']++;
            } else {
                $this->stats['filled_missing_count']++;
            }
            return;
        }

        $port->update($updates);

        if ($forceUpdate) {
            $this->line("  🔄 Updated: {$port->name} ({$port->code}) - {$port->unlocode}");
            $this->stats['updated_count']++;
        } else {
            $this->line("  📝 Filled missing: {$port->name} ({$port->code})");
            $this->stats['filled_missing_count']++;
        }
    }

    /**
     * Display results summary
     */
    private function displayResults(): void
    {
        $this->newLine();
        $this->info('📊 Sync Results:');
        $this->line("   Created: {$this->stats['created_count']}");
        $this->line("   Updated (force): {$this->stats['updated_count']}");
        $this->line("   Filled missing: {$this->stats['filled_missing_count']}");
        $this->line("   Skipped: {$this->stats['skipped_count']}");
        $this->line("   Conflicts: {$this->stats['conflicts_count']}");
        $this->line("   Ambiguous: {$this->stats['ambiguous_count']}");

        if (!empty($this->stats['countries_processed'])) {
            sort($this->stats['countries_processed']);
            $this->line("   Countries processed: " . implode(', ', $this->stats['countries_processed']));
        }

        // Show conflicts
        if (!empty($this->stats['conflicts'])) {
            $this->newLine();
            $this->warn('⚠️  Conflicts (first 20):');
            $conflicts = array_slice($this->stats['conflicts'], 0, 20);
            foreach ($conflicts as $conflict) {
                $this->line("   - Matched by {$conflict['matched_by']}:");
                $this->line("     Existing: {$conflict['existing']['name']} ({$conflict['existing']['code']}) - {$conflict['existing']['unlocode']}");
                $this->line("     New: {$conflict['new']['name']} ({$conflict['new']['code']}) - {$conflict['new']['unlocode']}");
            }
        }

        // Show ambiguous
        if (!empty($this->stats['ambiguous'])) {
            $this->newLine();
            $this->warn('⚠️  Ambiguous matches (first 20):');
            $ambiguous = array_slice($this->stats['ambiguous'], 0, 20);
            foreach ($ambiguous as $amb) {
                $this->line("   - {$amb['name']} ({$amb['country_code']}) - UN/LOCODE: {$amb['unlocode']}");
                $this->line("     Multiple existing ports found, skipping");
            }
        }
    }
}

