<?php

namespace App\Console\Commands;

use App\Models\RobawsArticleCache;
use App\Services\Robaws\RobawsArticleProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DiagnoseRobawsArticle extends Command
{
    protected $signature = 'articles:diagnose-robaws {article_id : The Robaws article ID to diagnose}';

    protected $description = 'Diagnose what Robaws API returns for a specific article';

    public function handle()
    {
        $robawsArticleId = $this->argument('article_id');
        
        $this->info("🔍 Fetching article {$robawsArticleId} from Robaws API...");
        $this->newLine();
        
        $provider = app(RobawsArticleProvider::class);
        
        try {
            $details = $provider->getArticleDetails($robawsArticleId);
            
            if (!$details) {
                $this->error('❌ Failed to fetch article from Robaws API');
                $this->warn('Possible reasons:');
                $this->line('  • API quota exceeded');
                $this->line('  • Article not found');
                $this->line('  • API authentication issue');
                return Command::FAILURE;
            }
            
            $this->info('✅ Article fetched successfully!');
            $this->newLine();
            
            // Show basic info
            $this->info('📋 BASIC INFO:');
            $this->line('  ID: ' . ($details['id'] ?? 'N/A'));
            $this->line('  Name: ' . ($details['name'] ?? 'N/A'));
            $this->line('  Code: ' . ($details['code'] ?? 'N/A'));
            $this->newLine();
            
            // Show extraFields structure
            if (isset($details['extraFields']) && !empty($details['extraFields'])) {
                $this->info('📦 EXTRA FIELDS:');
                $this->line('  Total fields: ' . count($details['extraFields']));
                $this->newLine();
                
                foreach ($details['extraFields'] as $fieldName => $fieldData) {
                    $type = $fieldData['type'] ?? 'UNKNOWN';
                    $value = $fieldData['stringValue'] 
                           ?? $fieldData['booleanValue'] 
                           ?? $fieldData['numberValue']
                           ?? $fieldData['value']
                           ?? 'NULL';
                    
                    // Highlight parent item specifically
                    if (strtoupper($fieldName) === 'PARENT ITEM' || str_contains(strtoupper($fieldName), 'PARENT')) {
                        $this->line("  🎯 {$fieldName}: {$value} (type: {$type}) ⬅️ PARENT ITEM FIELD");
                    } else {
                        $this->line("  • {$fieldName}: {$value} (type: {$type})");
                    }
                }
            } else {
                $this->warn('⚠️ No extraFields found in API response');
            }
            
            $this->newLine();
            
            // Show what's in our database
            $cachedArticle = RobawsArticleCache::where('robaws_article_id', $robawsArticleId)->first();
            
            if ($cachedArticle) {
                $this->info('💾 CURRENT DATABASE VALUES:');
                $this->line('  is_parent_item: ' . ($cachedArticle->is_parent_item ? 'TRUE' : 'FALSE'));
                $this->line('  commodity_type: ' . ($cachedArticle->commodity_type ?? 'NULL'));
                $this->line('  pod_code: ' . ($cachedArticle->pod_code ?? 'NULL'));
                $this->line('  shipping_line: ' . ($cachedArticle->shipping_line ?? 'NULL'));
                $this->line('  service_type: ' . ($cachedArticle->service_type ?? 'NULL'));
                $this->line('  pol_terminal: ' . ($cachedArticle->pol_terminal ?? 'NULL'));
            } else {
                $this->warn('⚠️ Article not found in cache');
            }
            
            $this->newLine();
            $this->info('✅ Diagnosis complete!');
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

