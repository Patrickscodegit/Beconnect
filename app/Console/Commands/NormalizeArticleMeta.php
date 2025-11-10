<?php

namespace App\Console\Commands;

use App\Models\RobawsArticleCache;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NormalizeArticleMeta extends Command
{
    protected $signature = 'robaws:normalize-article-meta
                            {--limit=0 : Maximum number of articles to process}
                            {--chunk=200 : Chunk size for processing}
                            {--article=* : Specific Robaws article ID(s) to normalize}
                            {--dry-run : Show what would change without saving}
                            {--sleep-ms=0 : Delay in milliseconds between batches}
                            {--skip-cost-side : Do not update the COST SIDE field}
                            {--skip-article-type : Do not update the ARTICLE TYPE field}';

    protected $description = 'Normalize ARTICLE TYPE and COST SIDE metadata for Robaws article cache';

    public function handle(): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $chunkSize = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $skipCostSide = (bool) $this->option('skip-cost-side');
        $skipArticleType = (bool) $this->option('skip-article-type');
        $articleFilter = collect($this->option('article'))->filter()->values();

        $query = RobawsArticleCache::query()->orderBy('id');

        if ($articleFilter->isNotEmpty()) {
            $query->whereIn('robaws_article_id', $articleFilter);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->warn('No articles matched the provided filters.');
            return Command::SUCCESS;
        }

        $this->info("Normalizing metadata for {$total} articles" . ($dryRun ? ' (dry-run)' : '') . '...');

        $stats = [
            'processed' => 0,
            'updated_type' => 0,
            'updated_cost_side' => 0,
            'skipped' => 0,
        ];

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();

        $query->chunkById($chunkSize, function (Collection $articles) use (&$stats, $dryRun, $skipArticleType, $skipCostSide, $sleepMs, $progressBar) {
            foreach ($articles as $article) {
                $stats['processed']++;

                $name = Str::lower((string) $article->article_name);
                $expectedType = $skipArticleType ? $article->article_type : $this->determineArticleType($name, $article->article_type);
                $expectedCostSide = $skipCostSide ? $article->cost_side : $this->determineCostSide($name, $expectedType, $article->cost_side);

                $changes = [];

                if (!$skipArticleType && $expectedType !== null && $expectedType !== $article->article_type) {
                    $changes['article_type'] = $expectedType;
                    $stats['updated_type']++;
                }

                if (!$skipCostSide && $expectedCostSide !== null && $expectedCostSide !== $article->cost_side) {
                    $changes['cost_side'] = $expectedCostSide;
                    $stats['updated_cost_side']++;
                }

                if (!empty($changes)) {
                    $this->logChange($article, $changes, $dryRun);

                    if (!$dryRun) {
                        $article->fill($changes);
                        $article->save();
                    }
                } else {
                    $stats['skipped']++;
                }

                $progressBar->advance();
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        });

        $progressBar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Processed', $stats['processed']],
                ['Updated Article Type', $stats['updated_type']],
                ['Updated Cost Side', $stats['updated_cost_side']],
                ['Unchanged', $stats['skipped']],
            ]
        );

        return Command::SUCCESS;
    }

    private function logChange(RobawsArticleCache $article, array $changes, bool $dryRun): void
    {
        $fields = collect($changes)->map(function ($value, $key) use ($article) {
            $before = $article->getAttribute($key);
            return "{$key}: {$before} -> {$value}";
        })->implode(', ');

        $tag = $dryRun ? '[DRY-RUN]' : '[UPDATE]';
        $this->line("{$tag} #{$article->id} {$article->article_name} {$fields}");
    }

    private function determineArticleType(string $name, ?string $currentType): ?string
    {
        if ($name === '') {
            return $currentType;
        }

        $patterns = [
            'SEAFREIGHT SURCHARGES' => '/\b(baf|caf|lss|war risk|surcharge|waf)\b/',
            'SEAFREIGHT' => '/seafreight|sea freight|ocean freight|freight/',
            'LOCAL CHARGES POL' => '/towing|tracks|\bthc\b|terminal|weighing|over(height|width|weight)|\bwaf\b|st332|st 332/',
            'LOCAL CHARGES POD' => '/dthc|delivery|destination|do fee|storage|pod\b/',
            'ROAD TRANSPORT SURCHARGES' => '/truck|transport|road|inland|haul(ing|age)/',
            'INSPECTION SURCHARGES' => '/inspection|imdg|imo|lpg|lpi|r67|r110|unece|en589/',
            'ADMINISTRATIVE / MISC. SURCHARGES' => '/waiver|ectn|ctn|besc|ens|bill of lading|\bbl\b|admin|courier|telex/',
        ];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $name)) {
                return $type;
            }
        }

        return $currentType;
    }

    private function determineCostSide(string $name, ?string $articleType, ?string $currentSide): ?string
    {
        $side = match (true) {
            str_contains((string) $articleType, 'LOCAL CHARGES POL') => 'POL',
            str_contains((string) $articleType, 'LOCAL CHARGES POD') => 'POD',
            str_contains((string) $articleType, 'SEAFREIGHT SURCHARGES'),
            str_contains((string) $articleType, 'SEAFREIGHT') => 'SEA',
            str_contains((string) $articleType, 'ROAD TRANSPORT') => 'INLAND',
            str_contains((string) $articleType, 'INSPECTION SURCHARGES') => 'POL',
            str_contains((string) $articleType, 'ADMINISTRATIVE') || str_contains((string) $articleType, 'MISC') => 'ADMIN',
            default => $currentSide,
        };

        $overrides = [
            'POD' => '/waiver|ectn|\bctn\b|besc|telex release/',
            'POL' => '/towing|tracks|\bthc\b|weighing|over(height|width|weight)|\bwaf\b|terminal|st332|st 332/',
            'SEA' => '/seafreight|baf|caf|lss|war risk|ocean freight/',
            'ADMIN' => '/bill of lading|\bbl\b|courier|admin/',
        ];

        foreach ($overrides as $target => $pattern) {
            if (preg_match($pattern, $name)) {
                return $target;
            }
        }

        return $side;
    }
}


