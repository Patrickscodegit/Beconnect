<?php

namespace App\Console\Commands;

use App\Models\RobawsArticleCache;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class NormalizeCostSide extends Command
{
    protected $signature = 'robaws:normalize-cost-side
                            {--limit=0 : Maximum number of articles to process}
                            {--dry-run : Show changes without saving}
                            {--article=* : Normalize only specific Robaws article IDs}';

    protected $description = 'Normalize COST SIDE metadata for Robaws articles using predefined heuristics';

    public function handle(): int
    {
        $limit = max((int) $this->option('limit'), 0);
        $dryRun = (bool) $this->option('dry-run');
        $articleFilter = collect($this->option('article'))->filter();

        $query = RobawsArticleCache::query()->orderBy('id');

        if ($articleFilter->isNotEmpty()) {
            $query->whereIn('robaws_article_id', $articleFilter);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $count = $query->count();

        if ($count === 0) {
            $this->warn('No matching articles found.');
            return Command::SUCCESS;
        }

        $updated = 0;
        $progress = $this->output->createProgressBar($count);
        $progress->start();

        $query->chunkById(200, function ($articles) use (&$updated, $dryRun, $progress) {
            foreach ($articles as $article) {
                $expected = $this->determineCostSide(
                    Str::lower((string) $article->article_name),
                    $article->article_type,
                    $article->cost_side
                );

                if ($expected && $expected !== $article->cost_side) {
                    $this->line(sprintf(
                        '%s #%d %s: %s -> %s',
                        $dryRun ? '[DRY-RUN]' : '[UPDATE]',
                        $article->id,
                        $article->article_name,
                        $article->cost_side ?? '(null)',
                        $expected
                    ));

                    if (!$dryRun) {
                        $article->cost_side = $expected;
                        $article->save();
                    }

                    $updated++;
                }

                $progress->advance();
            }
        });

        $progress->finish();
        $this->newLine(2);
        $this->info("Updated COST SIDE for {$updated} articles");

        return Command::SUCCESS;
    }

    private function determineCostSide(string $name, ?string $type, ?string $current): ?string
    {
        $side = match (true) {
            str_contains((string) $type, 'LOCAL CHARGES POL') => 'POL',
            str_contains((string) $type, 'LOCAL CHARGES POD') => 'POD',
            str_contains((string) $type, 'SEAFREIGHT SURCHARGES'),
            str_contains((string) $type, 'SEAFREIGHT') => 'SEA',
            str_contains((string) $type, 'AIRFREIGHT') => 'AIR',
            str_contains((string) $type, 'ROAD TRANSPORT') => 'INLAND',
            str_contains((string) $type, 'INSPECTION SURCHARGES') => 'POL',
            str_contains((string) $type, 'ADMINISTRATIVE') || str_contains((string) $type, 'MISC') => 'ADMIN',
            default => $current,
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


