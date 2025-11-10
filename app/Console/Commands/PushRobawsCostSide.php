<?php

namespace App\Console\Commands;

use App\Models\RobawsArticleCache;
use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PushRobawsCostSide extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'robaws:push-cost-side
                            {--limit=0 : Maximum number of articles to process}
                            {--chunk=50 : Chunk size for processing}
                            {--article=* : Specific Robaws article ID(s) to update}
                            {--dry-run : Show what would be updated without calling the API}
                            {--sleep-ms=0 : Delay in milliseconds between API requests}
                            {--skip-cost-side : Do not push the COST SIDE extra field}
                            {--skip-article-type : Do not push the ARTICLE TYPE extra field}';

    /**
     * The console command description.
     */
    protected $description = 'Push local cost_side metadata to Robaws article extra fields';

    public function handle(RobawsApiClient $client): int
    {
        $dryRun = (bool)$this->option('dry-run');
        $limit = (int) $this->option('limit');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $skipCostSide = (bool) $this->option('skip-cost-side');
        $skipArticleType = (bool) $this->option('skip-article-type');

        if ($skipCostSide && $skipArticleType) {
            $this->error('Both cost_side and article_type pushes are disabled. Nothing to do.');
            return self::INVALID;
        }

        $articleIds = collect($this->option('article'))
            ->filter()
            ->flatMap(fn ($value) => preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY))
            ->unique()
            ->values();

        $query = RobawsArticleCache::query()
            ->whereNotNull('robaws_article_id');

        if (! $skipCostSide) {
            $query->whereNotNull('cost_side');
        }

        if (! $skipArticleType) {
            $query->whereNotNull('article_type');
        }

        if ($articleIds->isNotEmpty()) {
            $query->whereIn('robaws_article_id', $articleIds);
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->warn('No articles found with cost_side values to push.');
            return self::SUCCESS;
        }

        if ($limit > 0) {
            $total = min($total, $limit);
        }

        $this->info(sprintf(
            'Preparing to %s %s for %d article(s).',
            $dryRun ? 'simulate pushing' : 'push',
            $this->describeFields($skipCostSide, $skipArticleType),
            $total
        ));

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();

        $processed = 0;
        $success = 0;
        $failed = 0;
        $errors = [];

        $query->orderBy('id')->chunkById($chunkSize, function (Collection $articles) use (
            $client,
            $dryRun,
            $limit,
            $progressBar,
            $sleepMs,
            $skipCostSide,
            $skipArticleType,
            &$processed,
            &$success,
            &$failed,
            &$errors
        ) {
            foreach ($articles as $article) {
                if ($limit > 0 && $processed >= $limit) {
                    return false; // stop chunking
                }

                $processed++;
                $extraFields = [];

                if (!$skipCostSide && !empty($article->cost_side)) {
                    $extraFields['COST SIDE'] = [
                        'type' => 'SELECT',
                        'group' => 'ARTICLE INFO',
                        'stringValue' => strtoupper($article->cost_side),
                    ];
                }

                if (!$skipArticleType && !empty($article->article_type)) {
                    $extraFields['ARTICLE TYPE'] = [
                        'type' => 'SELECT',
                        'group' => 'ARTICLE INFO',
                        'stringValue' => $article->article_type,
                    ];
                }

                if (empty($extraFields)) {
                    $failed++;
                    $errors[] = [
                        'robaws_article_id' => $article->robaws_article_id,
                        'article_name' => $article->article_name,
                        'cost_side' => $article->cost_side,
                        'status' => 0,
                        'error' => 'No extra fields available to push for this article.',
                    ];
                    Log::warning('No extra fields available to push for Robaws article', end($errors));
                    $progressBar->advance();
                    continue;
                }

                $payload = ['extraFields' => $extraFields];

                if ($dryRun) {
                    $success++;
                    Log::info('Dry-run: would push Robaws article extra fields', [
                        'robaws_article_id' => $article->robaws_article_id,
                        'article_name' => $article->article_name,
                        'cost_side' => $article->cost_side,
                        'article_type' => $article->article_type,
                        'fields' => array_keys($extraFields),
                    ]);
                } else {
                    $response = $client->updateArticle($article->robaws_article_id, $payload);

                    if ($response['success'] ?? false) {
                        $success++;
                    } else {
                        $failed++;
                        $errorMessage = $response['error'] ?? 'Unknown error';
                        $status = $response['status'] ?? 0;

                        $errors[] = [
                            'robaws_article_id' => $article->robaws_article_id,
                            'article_name' => $article->article_name,
                            'cost_side' => $article->cost_side,
                            'status' => $status,
                            'error' => $errorMessage,
                            'fields' => array_keys($extraFields),
                        ];

                        Log::warning('Failed to push Robaws article extra fields', end($errors));
                    }
                }

                $progressBar->advance();

                if ($sleepMs > 0 && !$dryRun) {
                    usleep($sleepMs * 1000);
                }
            }

            return true;
        });

        $progressBar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Processed', $processed],
                ['Success', $success],
                ['Failed', $failed],
                ['Mode', $dryRun ? 'DRY-RUN' : 'LIVE'],
            ]
        );

        if (!empty($errors)) {
            $this->warn('Some articles failed to update:');
            foreach ($errors as $error) {
                $this->line(sprintf(
                    ' - #%s (%s): [%s] %s',
                    $error['robaws_article_id'],
                    $error['article_name'],
                    $error['status'],
                    $error['error']
                ));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function describeFields(bool $skipCostSide, bool $skipArticleType): string
    {
        if ($skipCostSide && !$skipArticleType) {
            return 'ARTICLE TYPE';
        }

        if ($skipArticleType && !$skipCostSide) {
            return 'COST SIDE';
        }

        return 'COST SIDE and ARTICLE TYPE';
    }
}

