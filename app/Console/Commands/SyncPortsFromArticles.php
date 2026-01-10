<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Port;
use App\Models\RobawsArticleCache;

class SyncPortsFromArticles extends Command
{
    protected $signature = 'ports:sync-from-articles 
                            {--dry-run : Show what would be created without actually creating ports}
                            {--force : Overwrite existing ports}';

    protected $description = 'Sync ports from article cache data (extract PODs from articles)';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('🔄 Syncing ports from article cache...');
        $this->newLine();

        // Extract ports from articles
        $portsFromArticles = RobawsArticleCache::where(function ($query) {
            $query->whereNotNull('pod_code')
                  ->orWhereNotNull('pod');
        })
        ->select('pod_code', 'pod')
        ->distinct()
        ->get()
        ->map(function ($article) {
            $portData = null;

            if ($article->pod_code && $article->pod) {
                // Try to extract name and country from pod field
                // Format: "Port Name (CODE), Country"
                if (preg_match('/^([^(]+)\s*\(([A-Z]{3,4})\)\s*,\s*(.+)$/', $article->pod, $matches)) {
                    $portName = trim($matches[1]);
                    $portCode = trim($matches[2]);
                    $country = trim($matches[3]);
                    
                    // Determine region based on country
                    $region = $this->determineRegion($country);
                    
                    $portData = [
                        'code' => $portCode,
                        'name' => $portName,
                        'country' => $country,
                        'region' => $region,
                        'is_active' => true,
                    ];
                } elseif (preg_match('/^([^(]+)\s*\(([A-Z]{3,4})\)/', $article->pod, $matches)) {
                    // Format without country: "Port Name (CODE)"
                    $portName = trim($matches[1]);
                    $portCode = trim($matches[2]);
                    
                    $portData = [
                        'code' => $portCode,
                        'name' => $portName,
                        'country' => null,
                        'region' => null,
                        'is_active' => true,
                    ];
                } else {
                    // Fallback: use pod_code as both code and name
                    $portData = [
                        'code' => $article->pod_code,
                        'name' => $article->pod_code,
                        'country' => null,
                        'region' => null,
                        'is_active' => true,
                    ];
                }
            } elseif ($article->pod_code) {
                // Only pod_code available
                $portData = [
                    'code' => $article->pod_code,
                    'name' => $article->pod_code,
                    'country' => null,
                    'region' => null,
                    'is_active' => true,
                ];
            } elseif ($article->pod) {
                // Try to extract from pod field
                if (preg_match('/\(([A-Z]{3,4})\)/', $article->pod, $matches)) {
                    $portCode = $matches[1];
                    $portName = preg_replace('/\s*\([^)]+\)\s*.*$/', '', $article->pod);
                    
                    $portData = [
                        'code' => $portCode,
                        'name' => trim($portName),
                        'country' => null,
                        'region' => null,
                        'is_active' => true,
                    ];
                }
            }

            return $portData;
        })
        ->filter(function ($portData) {
            // Filter out invalid port codes (must be 3-4 uppercase letters)
            if (!$portData || !isset($portData['code'])) {
                return false;
            }
            $code = $portData['code'];
            
            // Valid port codes: 3-4 uppercase letters
            if (!preg_match('/^[A-Z]{3,4}$/', $code) || strlen($code) < 3) {
                return false;
            }
            
            // Filter out known invalid/special codes
            $invalidCodes = ['HULL', 'NMT']; // HULL might be a city name, NMT unknown
            if (in_array($code, $invalidCodes)) {
                return false;
            }
            
            return true;
        })
        ->unique('code')
        ->values();

        $this->info("Found {$portsFromArticles->count()} unique ports from articles");
        $this->newLine();

        if ($portsFromArticles->isEmpty()) {
            $this->warn('No ports found in article cache.');
            return 0;
        }

        // Display what will be created/updated
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($portsFromArticles as $portData) {
            $existing = Port::where('code', $portData['code'])->first();

            if ($existing) {
                if ($force) {
                    $this->line("  🔄 Updating: {$portData['code']} - {$portData['name']}");
                    if (!$dryRun) {
                        $existing->update($portData);
                    }
                    $updated++;
                } else {
                    $this->line("  ⏭️  Skipping (exists): {$portData['code']} - {$portData['name']}");
                    $skipped++;
                }
            } else {
                $this->line("  ✨ Creating: {$portData['code']} - {$portData['name']}" . 
                           ($portData['country'] ? " ({$portData['country']})" : ''));
                if (!$dryRun) {
                    Port::create($portData);
                }
                $created++;
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

    /**
     * Determine region based on country name
     */
    protected function determineRegion(?string $country): ?string
    {
        if (!$country) {
            return null;
        }

        $country = strtolower($country);

        // African countries
        $africanCountries = [
            'nigeria', 'senegal', 'ivory coast', "côte d'ivoire", 'guinea', 'benin',
            'cameroon', 'sierra leone', 'gabon', 'togo', 'mauritania', 'congo',
            'kenya', 'tanzania', 'south africa', 'morocco', 'algeria', 'tunisia',
            'ghana', 'niger', 'mali', 'burkina faso', 'liberia', 'gambia',
            'zimbabwe', 'mozambique', 'angola', 'namibia', 'botswana', 'zambia',
        ];

        if (in_array($country, $africanCountries) || str_contains($country, 'africa')) {
            return 'Africa';
        }

        // European countries
        $europeanCountries = [
            'belgium', 'netherlands', 'germany', 'france', 'spain', 'italy',
            'united kingdom', 'uk', 'portugal', 'greece', 'poland', 'sweden',
            'norway', 'denmark', 'finland', 'austria', 'switzerland',
        ];

        if (in_array($country, $europeanCountries) || str_contains($country, 'europe')) {
            return 'Europe';
        }

        // Middle East
        $middleEastCountries = [
            'saudi arabia', 'uae', 'united arab emirates', 'qatar', 'kuwait',
            'bahrain', 'oman', 'yemen', 'jordan', 'lebanon', 'israel',
        ];

        if (in_array($country, $middleEastCountries) || str_contains($country, 'middle east')) {
            return 'Middle East';
        }

        // North America
        if (str_contains($country, 'united states') || str_contains($country, 'usa') ||
            str_contains($country, 'canada') || str_contains($country, 'mexico')) {
            return 'North America';
        }

        // South America
        $southAmericanCountries = [
            'brazil', 'argentina', 'chile', 'uruguay', 'paraguay', 'peru',
            'colombia', 'venezuela', 'ecuador', 'bolivia',
        ];

        if (in_array($country, $southAmericanCountries)) {
            return 'South America';
        }

        // Asia
        if (str_contains($country, 'china') || str_contains($country, 'japan') ||
            str_contains($country, 'india') || str_contains($country, 'singapore') ||
            str_contains($country, 'thailand') || str_contains($country, 'vietnam')) {
            return 'Asia';
        }

        // Caribbean
        $caribbeanCountries = [
            'jamaica', 'trinidad', 'tobago', 'barbados', 'bahamas', 'cuba',
        ];

        if (in_array($country, $caribbeanCountries) || str_contains($country, 'caribbean')) {
            return 'Caribbean';
        }

        return null;
    }
}
