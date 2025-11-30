<?php

namespace App\Console\Commands;

use App\Models\RobawsArticleCache;
use Illuminate\Console\Command;

class CheckCompositeItem extends Command
{
    protected $signature = 'articles:check-composite 
                          {article_name : The article name to check (e.g., "Sallaum(ANR 332/740) Lagos Nigeria, LM Seafreight")}';

    protected $description = 'Check if an article is a parent and has composite items configured';

    public function handle()
    {
        $articleName = $this->argument('article_name');
        
        $article = RobawsArticleCache::where('article_name', 'LIKE', "%{$articleName}%")
            ->orWhere('article_code', 'LIKE', "%{$articleName}%")
            ->first();
        
        if (!$article) {
            $this->error("Article not found: {$articleName}");
            return Command::FAILURE;
        }
        
        $this->info("Found article:");
        $this->line("  ID: {$article->id}");
        $this->line("  Code: {$article->article_code}");
        $this->line("  Name: {$article->article_name}");
        $this->line("  Is Parent: " . ($article->is_parent_article ? 'YES' : 'NO'));
        $this->newLine();
        
        $children = $article->children;
        $this->info("Composite Items ({$children->count()}):");
        
        if ($children->count() === 0) {
            $this->warn("  No composite items found!");
        } else {
            foreach ($children as $child) {
                $childType = $child->pivot->child_type ?? 'optional';
                $this->line("  - {$child->article_code}: {$child->article_name}");
                $this->line("    Type: {$childType}");
                $this->line("    Required: " . ($child->pivot->is_required ? 'YES' : 'NO'));
                $this->line("    Conditional: " . ($child->pivot->is_conditional ? 'YES' : 'NO'));
                if ($child->pivot->conditions) {
                    $conditions = is_string($child->pivot->conditions) 
                        ? json_decode($child->pivot->conditions, true) 
                        : $child->pivot->conditions;
                    $this->line("    Conditions: " . json_encode($conditions));
                }
            }
        }
        
        return Command::SUCCESS;
    }
}

