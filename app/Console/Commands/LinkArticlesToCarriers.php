<?php

namespace App\Console\Commands;

use App\Models\RobawsArticleCache;
use App\Models\ShippingCarrier;
use App\Services\Carrier\CarrierLookupService;
use Illuminate\Console\Command;

class LinkArticlesToCarriers extends Command
{
    protected $signature = 'articles:link-to-carriers
                          {--carrier= : Specific carrier to link (grimaldi or sallaum)}
                          {--dry-run : Show what would be done without making changes}
                          {--force : Force update even if shipping_carrier_id is already set}';

    protected $description = 'Link articles to shipping carriers based on shipping_line field';

    protected CarrierLookupService $carrierLookup;

    public function __construct(CarrierLookupService $carrierLookup)
    {
        parent::__construct();
        $this->carrierLookup = $carrierLookup;
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $carrierFilter = $this->option('carrier');

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Build query for articles with shipping_line
        $query = RobawsArticleCache::whereNotNull('shipping_line');

        // Filter by carrier if specified
        if ($carrierFilter) {
            $carrierName = strtolower($carrierFilter);
            if ($carrierName === 'grimaldi') {
                $query->whereRaw('LOWER(shipping_line) LIKE ?', ['%grimaldi%']);
            } elseif ($carrierName === 'sallaum') {
                $query->whereRaw('LOWER(shipping_line) LIKE ?', ['%sallaum%']);
            }
        } else {
            // Default: only process Grimaldi and Sallaum
            $query->where(function ($q) {
                $q->whereRaw('LOWER(shipping_line) LIKE ?', ['%grimaldi%'])
                  ->orWhereRaw('LOWER(shipping_line) LIKE ?', ['%sallaum%']);
            });
        }

        // If not forcing, skip articles that already have shipping_carrier_id
        if (!$force) {
            $query->whereNull('shipping_carrier_id');
        }

        $articles = $query->get();
        $total = $articles->count();

        if ($total === 0) {
            $this->warn('No articles found to process.');
            $this->info('Make sure articles have shipping_line populated. Run: php artisan robaws:sync-extra-fields');
            return Command::SUCCESS;
        }

        $this->info("Processing {$total} article(s)...");
        $this->newLine();

        $linked = 0;
        $skipped = 0;
        $failed = 0;
        $alreadyLinked = 0;

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->setFormat('verbose');

        foreach ($articles as $article) {
            try {
                // Check if already linked (and not forcing)
                if (!$force && $article->shipping_carrier_id) {
                    $alreadyLinked++;
                    $progressBar->advance();
                    continue;
                }

                // Try to find carrier by shipping_line
                $carrier = $this->carrierLookup->findByCodeOrName($article->shipping_line);

                if (!$carrier) {
                    // Try to find by partial match
                    $carrier = $this->findCarrierByPartialMatch($article->shipping_line);
                }

                if (!$carrier) {
                    if ($dryRun) {
                        $this->newLine();
                        $this->line("  ⚠️  No carrier found for: {$article->shipping_line}");
                    }
                    $skipped++;
                    $progressBar->advance();
                    continue;
                }

                if ($dryRun) {
                    $this->newLine();
                    $this->line("  → Would link article '{$article->article_name}' to carrier '{$carrier->name}'");
                } else {
                    $article->update(['shipping_carrier_id' => $carrier->id]);
                    $linked++;
                }

            } catch (\Exception $e) {
                $failed++;
                $this->newLine();
                $this->error("  ❌ Error processing article {$article->robaws_article_id}: {$e->getMessage()}");
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->info('Summary:');
        $this->table(
            ['Action', 'Count'],
            [
                ['Total Processed', $total],
                ['Linked', $linked],
                ['Already Linked', $alreadyLinked],
                ['Skipped (No Carrier)', $skipped],
                ['Failed', $failed],
            ]
        );

        // Show carrier statistics
        if (!$dryRun && $linked > 0) {
            $this->newLine();
            $this->info('Articles by carrier:');
            $carrierStats = RobawsArticleCache::whereNotNull('shipping_carrier_id')
                ->whereIn('id', $articles->pluck('id'))
                ->with('shippingCarrier')
                ->get()
                ->groupBy('shipping_carrier_id')
                ->map(function ($group) {
                    $carrier = $group->first()->shippingCarrier;
                    return [
                        'carrier' => $carrier ? $carrier->name : 'Unknown',
                        'count' => $group->count()
                    ];
                })
                ->values()
                ->toArray();

            $this->table(
                ['Carrier', 'Article Count'],
                $carrierStats
            );
        }

        return Command::SUCCESS;
    }

    /**
     * Find carrier by partial match on shipping_line
     */
    protected function findCarrierByPartialMatch(string $shippingLine): ?ShippingCarrier
    {
        $normalized = strtolower(trim($shippingLine));

        // Try to find Grimaldi
        if (strpos($normalized, 'grimaldi') !== false) {
            return $this->carrierLookup->findGrimaldi();
        }

        // Try to find Sallaum
        if (strpos($normalized, 'sallaum') !== false) {
            return ShippingCarrier::where('code', 'SALLAUM')
                ->orWhereRaw('LOWER(name) LIKE ?', ['%sallaum%'])
                ->first();
        }

        return null;
    }
}
