<?php

namespace App\Console\Commands;

use App\Models\RobawsArticleCache;
use App\Services\Ports\PortResolutionService;
use Illuminate\Console\Command;

class BackfillRobawsArticlePorts extends Command
{
    protected $signature = 'ports:backfill-robaws-article-ports {--chunk=200 : Number of records processed per chunk} {--dry-run : Preview changes without saving}';

    protected $description = 'Backfill pol_port_id and pod_port_id foreign keys from existing pol_code and pod_code using PortResolutionService';

    public function __construct(
        protected PortResolutionService $portResolver
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');

        $stats = [
            'total' => 0,
            'pol_resolved' => 0,
            'pod_resolved' => 0,
            'flagged' => 0,
            'unresolved_codes' => [],
        ];

        $this->info('Starting backfill of port foreign keys...');
        if ($dryRun) {
            $this->warn('DRY-RUN mode: No changes will be saved');
        }

        RobawsArticleCache::query()
            ->select([
                'id',
                'robaws_article_id',
                'article_code',
                'article_name',
                'pol_code',
                'pod_code',
                'pol_port_id',
                'pod_port_id',
                'requires_manual_review',
            ])
            ->chunkById($chunkSize, function ($articles) use (&$stats, $dryRun, $chunkSize) {
                foreach ($articles as $article) {
                    $stats['total']++;
                    $updates = [];

                    // Resolve POL port if pol_code exists and pol_port_id is null
                    if ($article->pol_port_id === null && !empty($article->pol_code)) {
                        $polPort = $this->portResolver->resolveOne($article->pol_code, 'SEA');
                        if ($polPort) {
                            $updates['pol_port_id'] = $polPort->id;
                            $stats['pol_resolved']++;
                        } else {
                            // Track unresolved code
                            $code = strtoupper(trim($article->pol_code));
                            if (!in_array($code, $stats['unresolved_codes'])) {
                                $stats['unresolved_codes'][] = $code;
                            }
                        }
                    }

                    // Resolve POD port if pod_code exists and pod_port_id is null
                    if ($article->pod_port_id === null && !empty($article->pod_code)) {
                        $podPort = $this->portResolver->resolveOne($article->pod_code, 'SEA');
                        if ($podPort) {
                            $updates['pod_port_id'] = $podPort->id;
                            $stats['pod_resolved']++;
                        } else {
                            // Track unresolved code
                            $code = strtoupper(trim($article->pod_code));
                            if (!in_array($code, $stats['unresolved_codes'])) {
                                $stats['unresolved_codes'][] = $code;
                            }
                        }
                    }

                    // Set requires_manual_review flag
                    $hasPolCode = !empty($article->pol_code);
                    $hasPodCode = !empty($article->pod_code);
                    $polResolved = isset($updates['pol_port_id']) ? true : ($article->pol_port_id !== null);
                    $podResolved = isset($updates['pod_port_id']) ? true : ($article->pod_port_id !== null);

                    $needsReview = ($hasPolCode && !$polResolved) || ($hasPodCode && !$podResolved);
                    
                    if ($needsReview) {
                        $updates['requires_manual_review'] = true;
                        if ($article->requires_manual_review !== true) {
                            $stats['flagged']++;
                        }
                    } elseif ((!$hasPolCode && !$hasPodCode) || ($polResolved && (!$hasPodCode || $podResolved))) {
                        // Both resolved (or no codes to resolve) - clear flag
                        $updates['requires_manual_review'] = false;
                    }

                    // Update article if there are changes
                    if (!empty($updates)) {
                        if ($dryRun) {
                            $this->line(sprintf(
                                '[DRY-RUN] Article %s (%s): %s',
                                $article->robaws_article_id,
                                $article->article_code ?? 'N/A',
                                json_encode($updates, JSON_PRETTY_PRINT)
                            ));
                        } else {
                            $article->forceFill($updates);
                            $article->save();
                        }
                    }
                }

                // Progress indicator
                if ($stats['total'] % ($chunkSize * 5) === 0) {
                    $this->info("Processed {$stats['total']} articles...");
                }
            });

        // Output summary
        $this->newLine();
        $this->info('Backfill Summary:');
        $this->line("  Total processed: {$stats['total']}");
        $this->line("  POL resolved: {$stats['pol_resolved']}");
        $this->line("  POD resolved: {$stats['pod_resolved']}");
        $this->line("  Flagged for manual review: {$stats['flagged']}");
        
        if (!empty($stats['unresolved_codes'])) {
            $this->warn('  Unresolved codes (' . count($stats['unresolved_codes']) . '):');
            foreach (array_slice($stats['unresolved_codes'], 0, 20) as $code) {
                $this->line("    - {$code}");
            }
            if (count($stats['unresolved_codes']) > 20) {
                $remaining = count($stats['unresolved_codes']) - 20;
                $this->line("    ... and {$remaining} more");
            }
            $this->info('  Consider adding these codes to the port_aliases table and re-running the command.');
        }

        if ($dryRun) {
            $this->warn('DRY-RUN complete. Run without --dry-run to apply changes.');
        } else {
            $this->info('Backfill complete!');
        }

        return Command::SUCCESS;
    }
}
