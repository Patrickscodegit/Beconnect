<?php

namespace App\Console\Commands;

use App\Models\RobawsArticleCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AttachAdmin75ToParents extends Command
{
    protected $signature = 'admin:attach-75-to-parents {--dry-run : Show changes without writing} {--force : Skip confirmation}';
    protected $description = 'Attach Admin 75 as a child to all parent articles when missing';

    public function handle(): int
    {
        $admin75 = RobawsArticleCache::where('article_name', 'Admin 75')
            ->where('unit_price', 75)
            ->orderByRaw("CASE WHEN LOWER(unit_type) IN ('shipm.','shipm','shipment') THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->first();

        if (!$admin75) {
            $this->error('Admin 75 article not found.');
            return Command::FAILURE;
        }

        $parentArticles = RobawsArticleCache::query()
            ->where(function ($query) {
                $query->where('is_parent_item', true)
                    ->orWhere('is_parent_article', true);
            })
            ->get(['id', 'article_name']);

        if ($parentArticles->isEmpty()) {
            $this->info('No parent articles found.');
            return Command::SUCCESS;
        }

        $attachCount = 0;
        $skipped = 0;
        $dryRun = (bool) $this->option('dry-run');

        if (!$dryRun && !$this->option('force')) {
            if (!$this->confirm("Attach Admin 75 (ID {$admin75->id}) to {$parentArticles->count()} parent articles where missing?", false)) {
                $this->info('Cancelled.');
                return Command::SUCCESS;
            }
        }

        foreach ($parentArticles as $parent) {
            $exists = DB::table('article_children')
                ->where('parent_article_id', $parent->id)
                ->where('child_article_id', $admin75->id)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            $maxSortOrder = DB::table('article_children')
                ->where('parent_article_id', $parent->id)
                ->max('sort_order') ?? 0;

            if ($dryRun) {
                $attachCount++;
                continue;
            }

            DB::table('article_children')->insert([
                'parent_article_id' => $parent->id,
                'child_article_id' => $admin75->id,
                'sort_order' => $maxSortOrder + 1,
                'is_required' => true,
                'is_conditional' => false,
                'child_type' => 'mandatory',
                'conditions' => null,
                'cost_type' => 'Service',
                'default_quantity' => 1.0,
                'default_cost_price' => null,
                'unit_type' => $admin75->unit_type ?: 'SHIPM.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $attachCount++;
        }

        $this->info('Admin 75 attach completed.');
        $this->line("Attached: {$attachCount}");
        $this->line("Skipped: {$skipped}");

        return Command::SUCCESS;
    }
}
