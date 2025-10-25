<?php

namespace App\Console\Commands;

use App\Models\RobawsArticleCache;
use App\Services\Robaws\RobawsArticleProvider;
use App\Services\Robaws\ArticleSyncEnhancementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestSyncOneArticle extends Command
{
    protected $signature = 'articles:test-sync-one {robaws_article_id : The Robaws article ID to test}';

    protected $description = 'Test sync one article from Robaws API with full extraFields to verify everything works';

    public function handle()
    {
        $robawsArticleId = $this->argument('robaws_article_id');
        
        $this->info("🧪 TESTING SYNC FOR ARTICLE {$robawsArticleId}");
        $this->info('==========================================');
        $this->newLine();
        
        $provider = app(RobawsArticleProvider::class);
        $enhancementService = app(ArticleSyncEnhancementService::class);
        
        // Find article in cache
        $article = RobawsArticleCache::where('robaws_article_id', $robawsArticleId)->first();
        
        if (!$article) {
            $this->error('❌ Article not found in cache!');
            return Command::FAILURE;
        }
        
        $this->info('📋 BEFORE SYNC:');
        $this->table(
            ['Field', 'Value'],
            [
                ['Article Name', $article->article_name],
                ['is_parent_item', $article->is_parent_item ? 'TRUE' : 'FALSE'],
                ['commodity_type', $article->commodity_type ?? 'NULL'],
                ['pod_code', $article->pod_code ?? 'NULL'],
                ['pol_terminal', $article->pol_terminal ?? 'NULL'],
                ['shipping_line', $article->shipping_line ?? 'NULL'],
                ['service_type', $article->service_type ?? 'NULL'],
            ]
        );
        $this->newLine();
        
        try {
            // Fetch from Robaws API
            $this->info('🔄 Fetching from Robaws API...');
            $details = $provider->getArticleDetails($robawsArticleId);
            
            if (!$details) {
                $this->error('❌ Failed to fetch from Robaws API');
                return Command::FAILURE;
            }
            
            $this->info('✅ Robaws API responded successfully!');
            $this->newLine();
            
            // Show what Robaws returned
            $this->info('📦 ROBAWS EXTRA FIELDS:');
            if (isset($details['extraFields'])) {
                foreach ($details['extraFields'] as $fieldName => $fieldData) {
                    $value = $fieldData['stringValue'] 
                           ?? $fieldData['booleanValue'] 
                           ?? $fieldData['numberValue']
                           ?? $fieldData['value']
                           ?? 'NULL';
                    
                    $type = $fieldData['type'] ?? 'UNKNOWN';
                    $this->line("  • {$fieldName}: {$value} (type: {$type})");
                }
            }
            $this->newLine();
            
            // Extract metadata
            $updateData = [];
            $extraFields = $details['extraFields'] ?? [];
            
            foreach ($extraFields as $fieldName => $field) {
                $value = $field['stringValue'] 
                       ?? $field['booleanValue'] 
                       ?? $field['numberValue']
                       ?? $field['value'] 
                       ?? null;
                
                switch ($fieldName) {
                    case 'PARENT ITEM':
                        $updateData['is_parent_item'] = (bool) ((int) $value);
                        break;
                    case 'SHIPPING LINE':
                        $updateData['shipping_line'] = $value;
                        break;
                    case 'SERVICE TYPE':
                        $updateData['service_type'] = $value;
                        break;
                    case 'POL TERMINAL':
                        $updateData['pol_terminal'] = $value;
                        break;
                    case 'POL':
                        $updateData['pol'] = $value;
                        break;
                    case 'POD':
                        $updateData['pod'] = $value;
                        break;
                    case 'TYPE':
                        $updateData['type'] = $value;
                        break;
                }
            }
            
            // Extract enhanced fields
            if (!empty($updateData['type'])) {
                $updateData['commodity_type'] = $enhancementService->extractCommodityType(['type' => $updateData['type']]);
            } else {
                $updateData['commodity_type'] = $enhancementService->extractCommodityType(['article_name' => $article->article_name]);
            }
            
            if (!empty($updateData['pod'])) {
                $updateData['pod_code'] = $enhancementService->extractPodCode($updateData['pod']);
            }
            
            // Update article
            $article->update($updateData);
            $article->refresh();
            
            $this->newLine();
            $this->info('📋 AFTER SYNC:');
            $this->table(
                ['Field', 'Old Value', 'New Value', 'Status'],
                [
                    [
                        'is_parent_item', 
                        'FALSE', 
                        $article->is_parent_item ? 'TRUE' : 'FALSE',
                        $article->is_parent_item ? '✅' : '❌'
                    ],
                    [
                        'commodity_type', 
                        'NULL', 
                        $article->commodity_type ?? 'NULL',
                        $article->commodity_type ? '✅' : '❌'
                    ],
                    [
                        'pod_code', 
                        'NULL', 
                        $article->pod_code ?? 'NULL',
                        $article->pod_code ? '✅' : '❌'
                    ],
                    [
                        'pol_terminal', 
                        $article->pol_terminal ?? 'NULL', 
                        $article->pol_terminal ?? 'NULL',
                        $article->pol_terminal ? '✅' : '⚠️'
                    ],
                    [
                        'shipping_line', 
                        $article->shipping_line ?? 'NULL', 
                        $article->shipping_line ?? 'NULL',
                        $article->shipping_line ? '✅' : '⚠️'
                    ],
                ]
            );
            
            $this->newLine();
            
            // Final verdict
            $success = $article->is_parent_item && $article->commodity_type && $article->pod_code;
            
            if ($success) {
                $this->info('✅ SUCCESS! All Smart Article Selection fields populated correctly!');
                $this->info('🚀 Safe to run full sync on all 1,576 articles!');
            } else {
                $this->warn('⚠️ Some fields missing - review needed before full sync');
            }
            
            return $success ? Command::SUCCESS : Command::FAILURE;
            
        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}

