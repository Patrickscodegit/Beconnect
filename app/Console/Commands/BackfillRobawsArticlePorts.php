<?php

namespace App\Console\Commands;

use App\Models\RobawsArticleCache;
use App\Services\Robaws\ArticleNameParser;
use App\Services\Robaws\ArticleSyncEnhancementService;
use App\Services\Robaws\ArticleTransportModeResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class BackfillRobawsArticlePorts extends Command
{
    protected $signature = 'robaws:articles:backfill-ports {--chunk=200 : Number of records processed per chunk} {--dry-run : Preview changes without saving}';

    protected $description = 'Populate missing POL/POD strings and codes on robaws_articles_cache records';

    public function __construct(
        protected ArticleNameParser $parser,
        protected ArticleSyncEnhancementService $enhancementService,
        protected ArticleTransportModeResolver $transportModeResolver
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;

        RobawsArticleCache::query()
            ->select([
                'id',
                'robaws_article_id',
                'article_name',
                'article_code',
                'description',
                'category',
                'shipping_line',
                'commodity_type',
                'transport_mode',
                'pol',
                'pod',
                'pol_code',
                'pod_code',
                'pol_terminal',
            ])
            ->chunkById($chunkSize, function ($articles) use (&$updated, $dryRun) {
                foreach ($articles as $article) {
                    $updates = $this->buildUpdates($article->toArray());

                    if (empty($updates)) {
                        continue;
                    }

                    $updated++;

                    if ($dryRun) {
                        $this->line(sprintf(
                            '[DRY-RUN] Article %s (%s): %s',
                            $article->robaws_article_id,
                            $article->article_name,
                            json_encode($updates)
                        ));
                        continue;
                    }

                    $article->forceFill($updates);
                    $article->save();
                }
            });

        $this->info(sprintf(
            $dryRun ? 'Dry run complete. %d records would be updated.' : 'Backfill complete. %d records updated.',
            $updated
        ));

        return Command::SUCCESS;
    }

    protected function buildUpdates(array $article): array
    {
        $updates = [];

        $pol = Arr::get($article, 'pol');
        $pod = Arr::get($article, 'pod');
        $polCode = Arr::get($article, 'pol_code');
        $podCode = Arr::get($article, 'pod_code');
        $polTerminal = Arr::get($article, 'pol_terminal');

        // Attempt to parse from article name when missing
        $parsed = $this->parserData($article['article_name'] ?? '');

        if (empty($pol) && !empty($parsed['pol'])) {
            $updates['pol'] = $parsed['pol'];
        }

        if (empty($pod) && !empty($parsed['pod'])) {
            $updates['pod'] = $parsed['pod'];
        }

        if (empty($polTerminal) && !empty($parsed['pol_terminal'])) {
            $updates['pol_terminal'] = $parsed['pol_terminal'];
        }

        // Codes: prefer enhancement service to normalize format
        $polSource = $updates['pol'] ?? $pol ?? $parsed['pol'] ?? null;
        $podSource = $updates['pod'] ?? $pod ?? $parsed['pod'] ?? null;

        if ($this->needsCodeNormalization($polCode)) {
            $code = $this->enhancementService->extractPolCode($polSource);
            if (!empty($code)) {
                $updates['pol_code'] = $code;
            }
        }

        if ($this->needsCodeNormalization($podCode)) {
            $code = $this->enhancementService->extractPodCode($podSource);
            if (!empty($code)) {
                $updates['pod_code'] = $code;
            }
        }

        $context = [
            'transport_mode' => Arr::get($article, 'transport_mode'),
            'shipping_line' => Arr::get($article, 'shipping_line'),
            'commodity_type' => Arr::get($article, 'commodity_type'),
            'category' => Arr::get($article, 'category'),
            'description' => Arr::get($article, 'description'),
            'article_code' => Arr::get($article, 'article_code'),
        ];

        $resolvedMode = $this->transportModeResolver->resolve($article['article_name'] ?? '', $context);
        if ($resolvedMode && $resolvedMode !== Arr::get($article, 'transport_mode')) {
            $updates['transport_mode'] = $resolvedMode;
        }

        return $updates;
    }

    protected function parserData(string $articleName): array
    {
        $data = [];

        if (empty($articleName)) {
            return $data;
        }

        $polData = $this->parser->extractPOL($articleName);
        if ($polData) {
            $data['pol'] = $polData['formatted'] ?? $polData['code'] ?? null;
            $data['pol_code'] = $polData['code'] ?? null;
            if (!empty($polData['terminal'])) {
                $data['pol_terminal'] = $polData['terminal'];
            }
        }

        $podData = $this->parser->extractPOD($articleName);
        if ($podData) {
            $data['pod'] = $podData['formatted'] ?? $podData['code'] ?? null;
            $data['pod_code'] = $podData['code'] ?? null;
        }

        return $data;
    }

    protected function needsCodeNormalization(?string $value): bool
    {
        if (empty($value)) {
            return true;
        }

        $trimmed = trim($value);

        if (strlen($trimmed) > 4) {
            return true;
        }

        if (preg_match('/[^A-Z]/', $trimmed)) {
            return true;
        }

        return false;
    }
}

