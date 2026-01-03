<?php

namespace App\Console\Commands;

use App\Models\Port;
use App\Models\PortAlias;
use App\Models\RobawsArticleCache;
use App\Models\CarrierArticleMapping;
use App\Models\ShippingSchedule;
use App\Services\Ports\PortResolutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditPortsNormalization extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ports:audit-normalization {--json : Output results as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit port normalization: detect duplicates, orphans, invalid mappings, unresolved inputs';

    private PortResolutionService $portResolver;

    public function __construct()
    {
        parent::__construct();
        $this->portResolver = app(PortResolutionService::class);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $results = [
            'duplicate_like_ports' => $this->findDuplicateLikePorts(),
            'orphan_ports' => $this->findOrphanPorts(),
            'invalid_mapping_references' => $this->findInvalidMappingReferences(),
            'unresolved_robaws_inputs' => $this->findUnresolvedRobawsInputs(),
            'airport_seaport_completeness' => $this->checkAirportSeaportCompleteness(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $this->displayResults($results);

        return Command::SUCCESS;
    }

    /**
     * Find duplicate-like ports
     */
    private function findDuplicateLikePorts(): array
    {
        $this->info('Checking for duplicate-like ports...');

        // Same name different codes
        $sameNameDifferentCodes = Port::select('name', DB::raw('COUNT(*) as count'))
            ->groupBy('name')
            ->having('count', '>', 1)
            ->get()
            ->map(function($row) {
                $ports = Port::where('name', $row->name)->get();
                return [
                    'name' => $row->name,
                    'codes' => $ports->pluck('code')->toArray(),
                    'count' => $row->count,
                ];
            })
            ->toArray();

        // Suspicious near-duplicates (case-insensitive)
        $nearDuplicates = DB::table('ports as p1')
            ->join('ports as p2', function($join) {
                $join->on(DB::raw('LOWER(p1.name)'), '=', DB::raw('LOWER(p2.name)'))
                     ->whereColumn('p1.id', '!=', 'p2.id');
            })
            ->select('p1.id', 'p1.name', 'p1.code', 'p2.id as duplicate_id', 'p2.name as duplicate_name', 'p2.code as duplicate_code')
            ->get()
            ->toArray();

        return [
            'same_name_different_codes' => $sameNameDifferentCodes,
            'near_duplicates' => $nearDuplicates,
        ];
    }

    /**
     * Find orphan ports (not referenced anywhere)
     */
    private function findOrphanPorts(): array
    {
        $this->info('Checking for orphan ports...');

        // Ports not referenced by shipping_schedules
        $notInSchedules = Port::whereDoesntHave('polSchedules')
            ->whereDoesntHave('podSchedules')
            ->get()
            ->map(fn($port) => [
                'id' => $port->id,
                'name' => $port->name,
                'code' => $port->code,
            ])
            ->toArray();

        // Ports not present in any carrier_article_mappings.port_ids
        $allPortIdsInMappings = CarrierArticleMapping::whereNotNull('port_ids')
            ->get()
            ->flatMap(fn($mapping) => $mapping->port_ids ?? [])
            ->unique()
            ->toArray();

        $notInMappings = Port::whereNotIn('id', $allPortIdsInMappings)
            ->get()
            ->map(fn($port) => [
                'id' => $port->id,
                'name' => $port->name,
                'code' => $port->code,
            ])
            ->toArray();

        return [
            'not_in_schedules' => $notInSchedules,
            'not_in_mappings' => $notInMappings,
        ];
    }

    /**
     * Find invalid mapping references
     */
    private function findInvalidMappingReferences(): array
    {
        $this->info('Checking for invalid mapping references...');

        $allPortIds = Port::pluck('id')->toArray();
        
        $invalidMappings = CarrierArticleMapping::whereNotNull('port_ids')
            ->get()
            ->filter(function($mapping) use ($allPortIds) {
                $portIds = $mapping->port_ids ?? [];
                return !empty(array_diff($portIds, $allPortIds));
            })
            ->map(fn($mapping) => [
                'id' => $mapping->id,
                'carrier_id' => $mapping->carrier_id,
                'port_ids' => $mapping->port_ids,
                'invalid_ids' => array_diff($mapping->port_ids ?? [], $allPortIds),
            ])
            ->toArray();

        return [
            'count' => count($invalidMappings),
            'mappings' => $invalidMappings,
        ];
    }

    /**
     * Find unresolved Robaws inputs
     */
    private function findUnresolvedRobawsInputs(): array
    {
        $this->info('Checking for unresolved Robaws inputs...');

        // Distinct pod_code values that don't resolve
        $distinctPodCodes = RobawsArticleCache::whereNotNull('pod_code')
            ->distinct()
            ->pluck('pod_code')
            ->filter()
            ->toArray();

        $unresolvedPodCodes = [];
        foreach ($distinctPodCodes as $podCode) {
            $port = $this->portResolver->resolveOne($podCode);
            if (!$port) {
                $unresolvedPodCodes[] = $podCode;
            }
        }

        // Top N distinct pod strings that don't resolve
        $distinctPodStrings = RobawsArticleCache::whereNotNull('pod')
            ->distinct()
            ->pluck('pod')
            ->filter()
            ->take(50) // Limit to top 50 for performance
            ->toArray();

        $unresolvedPodStrings = [];
        foreach ($distinctPodStrings as $podString) {
            $port = $this->portResolver->resolveOne($podString);
            if (!$port) {
                $unresolvedPodStrings[] = $podString;
            }
        }

        return [
            'unresolved_pod_codes' => [
                'count' => count($unresolvedPodCodes),
                'samples' => array_slice($unresolvedPodCodes, 0, 20), // Top 20 samples
            ],
            'unresolved_pod_strings' => [
                'count' => count($unresolvedPodStrings),
                'samples' => array_slice($unresolvedPodStrings, 0, 20), // Top 20 samples
            ],
        ];
    }

    /**
     * Display results in human-readable format
     */
    private function displayResults(array $results): void
    {
        $this->newLine();
        $this->info('=== Port Normalization Audit Results ===');
        $this->newLine();

        // Duplicate-like ports
        $this->info('1. Duplicate-like Ports:');
        $duplicates = $results['duplicate_like_ports'];
        if (!empty($duplicates['same_name_different_codes'])) {
            $this->warn('   Same name, different codes:');
            foreach ($duplicates['same_name_different_codes'] as $dup) {
                $this->line("     - {$dup['name']}: " . implode(', ', $dup['codes']));
            }
        }
        if (!empty($duplicates['near_duplicates'])) {
            $this->warn('   Near duplicates (case-insensitive):');
            foreach (array_slice($duplicates['near_duplicates'], 0, 10) as $dup) {
                $this->line("     - {$dup->name} ({$dup->code}) vs {$dup->duplicate_name} ({$dup->duplicate_code})");
            }
        }
        $this->newLine();

        // Orphan ports
        $this->info('2. Orphan Ports:');
        $orphans = $results['orphan_ports'];
        $this->line("   Not in schedules: " . count($orphans['not_in_schedules']));
        $this->line("   Not in mappings: " . count($orphans['not_in_mappings']));
        if (!empty($orphans['not_in_schedules'])) {
            $this->warn('   Sample ports not in schedules:');
            foreach (array_slice($orphans['not_in_schedules'], 0, 10) as $port) {
                $this->line("     - {$port['name']} ({$port['code']})");
            }
        }
        $this->newLine();

        // Invalid mapping references
        $this->info('3. Invalid Mapping References:');
        $invalid = $results['invalid_mapping_references'];
        $this->line("   Count: {$invalid['count']}");
        if (!empty($invalid['mappings'])) {
            $this->warn('   Sample invalid mappings:');
            foreach (array_slice($invalid['mappings'], 0, 10) as $mapping) {
                $this->line("     - Mapping ID {$mapping['id']}: Invalid port IDs " . implode(', ', $mapping['invalid_ids']));
            }
        }
        $this->newLine();

        // Unresolved Robaws inputs
        $this->info('4. Unresolved Robaws Inputs:');
        $unresolved = $results['unresolved_robaws_inputs'];
        $this->line("   Unresolved pod_code values: {$unresolved['unresolved_pod_codes']['count']}");
        if (!empty($unresolved['unresolved_pod_codes']['samples'])) {
            $this->warn('   Sample unresolved pod_codes:');
            foreach ($unresolved['unresolved_pod_codes']['samples'] as $code) {
                $this->line("     - {$code}");
            }
        }
        $this->line("   Unresolved pod strings: {$unresolved['unresolved_pod_strings']['count']}");
        if (!empty($unresolved['unresolved_pod_strings']['samples'])) {
            $this->warn('   Sample unresolved pod strings:');
            foreach ($unresolved['unresolved_pod_strings']['samples'] as $pod) {
                $this->line("     - {$pod}");
            }
        }
        $this->newLine();

        // Airport/Seaport completeness
        $this->info('5. Airport/Seaport Completeness:');
        $completeness = $results['airport_seaport_completeness'];
        $this->line("   AIRPORT ports missing iata_code: {$completeness['airports_missing_iata']}");
        $this->line("   SEA_PORT ports missing unlocode: {$completeness['seaports_missing_unlocode']}");
        $this->line("   UNKNOWN category ports: {$completeness['unknown_category_count']}");
        $this->newLine();
    }

    /**
     * Check airport/seaport completeness
     */
    private function checkAirportSeaportCompleteness(): array
    {
        $this->info('Checking airport/seaport completeness...');

        // AIRPORT ports missing iata_code
        $airportsMissingIata = Port::where('port_category', 'AIRPORT')
            ->where(function($q) {
                $q->whereNull('iata_code')
                  ->orWhere('iata_code', '');
            })
            ->count();

        // SEA_PORT ports missing unlocode
        $seaportsMissingUnlocode = Port::where('port_category', 'SEA_PORT')
            ->where(function($q) {
                $q->whereNull('unlocode')
                  ->orWhere('unlocode', '');
            })
            ->count();

        // UNKNOWN category ports
        $unknownCategoryCount = Port::where('port_category', 'UNKNOWN')
            ->count();

        return [
            'airports_missing_iata' => $airportsMissingIata,
            'seaports_missing_unlocode' => $seaportsMissingUnlocode,
            'unknown_category_count' => $unknownCategoryCount,
        ];
    }
}

