<?php

namespace App\Console\Commands;

use App\Models\CarrierArticleMapping;
use App\Models\CarrierPurchaseTariff;
use App\Models\Port;
use App\Models\RobawsArticleCache;
use App\Models\ShippingCarrier;
use Illuminate\Console\Command;

class AuditGrimaldiPurchaseTariffs extends Command
{
    protected $signature = 'grimaldi:audit-purchase-tariffs';
    protected $description = 'Audit Grimaldi purchase tariffs to identify missing destinations';

    public function handle()
    {
        $this->info('🔍 Auditing Grimaldi Purchase Tariffs...');
        $this->newLine();

        // Find Grimaldi carrier
        $carrier = ShippingCarrier::where('code', 'GRIMALDI')->first();
        if (!$carrier) {
            $this->error('❌ Grimaldi carrier not found.');
            return 1;
        }

        $this->info("✓ Found carrier: {$carrier->name} (ID: {$carrier->id})");
        $this->newLine();

        // Expected ports from seeder
        $expectedPorts = ['ABJ', 'FNA', 'BJL', 'LOS', 'CAS', 'TFN', 'CKY', 'LFW', 'COO', 'DKR', 'DLA', 'LAD', 'ROB', 'BTA', 'MAL', 'PNR', 'LBV', 'NKC', 'TEM', 'TKR'];
        $expectedCategories = ['CAR', 'SMALL_VAN', 'BIG_VAN', 'LM'];

        $this->info('📊 Expected Ports: ' . count($expectedPorts));
        $this->info('📊 Expected Categories per Port: ' . count($expectedCategories));
        $this->info('📊 Total Expected Tariffs: ' . (count($expectedPorts) * count($expectedCategories)));
        $this->newLine();

        $stats = [
            'ports_missing' => [],
            'ports_without_articles' => [],
            'ports_without_mappings' => [],
            'ports_without_tariffs' => [],
            'ports_complete' => [],
            'total_tariffs' => 0,
        ];

        // Check each expected port
        foreach ($expectedPorts as $portCode) {
            $port = Port::where('code', $portCode)->first();
            
            if (!$port) {
                $stats['ports_missing'][] = $portCode;
                continue;
            }

            $portIssues = [];
            $hasAllTariffs = true;

            // Check each category
            foreach ($expectedCategories as $category) {
                // Check if article exists
                $suffix = $this->getCategorySuffix($category);
                $expectedCode = 'GANR' . $portCode . $suffix;
                
                $article = RobawsArticleCache::where('article_code', $expectedCode)
                    ->where('is_active', true)
                    ->first();

                if (!$article) {
                    $portIssues[] = "Missing article: {$expectedCode}";
                    $hasAllTariffs = false;
                    continue;
                }

                // Check if mapping exists
                $mapping = CarrierArticleMapping::where('carrier_id', $carrier->id)
                    ->where('article_id', $article->id)
                    ->whereJsonContains('port_ids', $port->id)
                    ->first();

                if (!$mapping) {
                    $portIssues[] = "Missing mapping for article: {$expectedCode}";
                    $hasAllTariffs = false;
                    continue;
                }

                // Check if active tariff exists
                $tariff = CarrierPurchaseTariff::where('carrier_article_mapping_id', $mapping->id)
                    ->where('is_active', true)
                    ->where('effective_from', '>=', '2026-01-01')
                    ->first();

                if (!$tariff) {
                    $portIssues[] = "Missing active tariff for: {$category}";
                    $hasAllTariffs = false;
                } else {
                    $stats['total_tariffs']++;
                }
            }

            if (!empty($portIssues)) {
                if (strpos(implode(' ', $portIssues), 'article') !== false) {
                    $stats['ports_without_articles'][] = ['port' => $portCode, 'issues' => $portIssues];
                } elseif (strpos(implode(' ', $portIssues), 'mapping') !== false) {
                    $stats['ports_without_mappings'][] = ['port' => $portCode, 'issues' => $portIssues];
                } else {
                    $stats['ports_without_tariffs'][] = ['port' => $portCode, 'issues' => $portIssues];
                }
            } else {
                $stats['ports_complete'][] = $portCode;
            }
        }

        // Display results
        $this->displayResults($stats, $expectedPorts, $expectedCategories);

        return 0;
    }

    private function getCategorySuffix(string $category): string
    {
        return match($category) {
            'CAR' => 'CAR',
            'SMALL_VAN' => 'SV',
            'BIG_VAN' => 'BV',
            'LM' => 'HH',
            default => '',
        };
    }

    private function displayResults(array $stats, array $expectedPorts, array $expectedCategories): void
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('📋 AUDIT RESULTS');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->newLine();

        // Missing ports
        if (!empty($stats['ports_missing'])) {
            $this->error('❌ Missing Ports (' . count($stats['ports_missing']) . '):');
            foreach ($stats['ports_missing'] as $portCode) {
                $this->line("   - {$portCode}");
            }
            $this->newLine();
        }

        // Ports without articles
        if (!empty($stats['ports_without_articles'])) {
            $this->warn('⚠️  Ports Missing Articles (' . count($stats['ports_without_articles']) . '):');
            foreach ($stats['ports_without_articles'] as $item) {
                $this->line("   - {$item['port']}:");
                foreach ($item['issues'] as $issue) {
                    $this->line("     • {$issue}");
                }
            }
            $this->newLine();
        }

        // Ports without mappings
        if (!empty($stats['ports_without_mappings'])) {
            $this->warn('⚠️  Ports Missing Mappings (' . count($stats['ports_without_mappings']) . '):');
            foreach ($stats['ports_without_mappings'] as $item) {
                $this->line("   - {$item['port']}:");
                foreach ($item['issues'] as $issue) {
                    $this->line("     • {$issue}");
                }
            }
            $this->newLine();
        }

        // Ports without tariffs
        if (!empty($stats['ports_without_tariffs'])) {
            $this->warn('⚠️  Ports Missing Tariffs (' . count($stats['ports_without_tariffs']) . '):');
            foreach ($stats['ports_without_tariffs'] as $item) {
                $this->line("   - {$item['port']}:");
                foreach ($item['issues'] as $issue) {
                    $this->line("     • {$issue}");
                }
            }
            $this->newLine();
        }

        // Complete ports
        $this->info('✅ Complete Ports (' . count($stats['ports_complete']) . '):');
        if (!empty($stats['ports_complete'])) {
            $this->line('   ' . implode(', ', $stats['ports_complete']));
        } else {
            $this->line('   None');
        }
        $this->newLine();

        // Summary
        $expectedTotal = count($expectedPorts) * count($expectedCategories);
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('📊 SUMMARY');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info("Total Expected Tariffs: {$expectedTotal}");
        $this->info("Total Found Tariffs: {$stats['total_tariffs']}");
        $this->info("Missing Tariffs: " . ($expectedTotal - $stats['total_tariffs']));
        $this->info("Completion: " . round(($stats['total_tariffs'] / $expectedTotal) * 100, 1) . "%");
        $this->newLine();

        // Recommendations
        if (!empty($stats['ports_without_tariffs']) || !empty($stats['ports_without_mappings']) || !empty($stats['ports_without_articles'])) {
            $this->warn('💡 RECOMMENDATION: Run the seeder to populate missing data:');
            $this->line('   php artisan db:seed --class=PopulateGrimaldiPurchaseTariffs');
            $this->newLine();
        }
    }
}

