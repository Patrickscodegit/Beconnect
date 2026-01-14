<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\QuotationRequest;
use App\Models\QuotationCommodityItem;
use App\Models\QuotationRequestArticle;
use App\Services\CarrierRules\CarrierRuleResolver;

class AuditQuotation extends Command
{
    protected $signature = 'quotation:audit {request_number : The quotation request number (e.g., QR-2026-0006)}';
    protected $description = 'Comprehensive audit of quotation article selection and towing logic';

    public function handle()
    {
        $requestNumber = $this->argument('request_number');
        
        $quotation = QuotationRequest::where('request_number', $requestNumber)->first();
        
        if (!$quotation) {
            $this->error("Quotation not found: {$requestNumber}");
            return 1;
        }
        
        $this->info("=== AUDIT REPORT: {$requestNumber} ===");
        $this->newLine();
        
        // Step 1: Quotation Basic Data
        $this->displayQuotationData($quotation);
        
        // Step 2: Commodity Items Analysis
        $this->displayCommodityItems($quotation);
        
        // Step 3: Selected Articles
        $this->displaySelectedArticles($quotation);
        
        // Step 4: Towing Logic Trace
        $this->traceTowingLogic($quotation);
        
        // Step 5: Article Selection Analysis
        $this->analyzeArticleSelection($quotation);
        
        return 0;
    }
    
    private function displayQuotationData(QuotationRequest $quotation)
    {
        $this->info("📋 QUOTATION DATA");
        $this->line("ID: {$quotation->id}");
        $this->line("Request Number: {$quotation->request_number}");
        $this->line("POL: {$quotation->pol}");
        $this->line("POD: {$quotation->pod}");
        $this->line("Service Type: {$quotation->service_type}");
        
        if ($quotation->selectedSchedule) {
            $schedule = $quotation->selectedSchedule;
            $this->line("Schedule ID: {$schedule->id}");
            $this->line("Carrier: " . ($schedule->carrier->name ?? 'N/A'));
            $this->line("Vessel: " . ($schedule->vessel_name ?? 'N/A'));
            $this->line("POD Port ID: " . ($schedule->pod_id ?? 'N/A'));
        } else {
            $this->warn("No schedule selected");
        }
        
        $this->newLine();
    }
    
    private function displayCommodityItems(QuotationRequest $quotation)
    {
        $this->info("📦 COMMODITY ITEMS");
        
        $items = $quotation->commodityItems()->orderBy('line_number')->get();
        
        if ($items->isEmpty()) {
            $this->warn("No commodity items found");
            $this->newLine();
            return;
        }
        
        $this->table(
            ['Line', 'ID', 'Category', 'Type', 'Relationship', 'Related To', 'Quantity', 'Stack Qty'],
            $items->map(function ($item) {
                $relatedTo = 'N/A';
                if ($item->related_item_id) {
                    $related = QuotationCommodityItem::find($item->related_item_id);
                    $relatedTo = $related ? "Line {$related->line_number} ({$related->category})" : "ID {$item->related_item_id}";
                }
                
                return [
                    $item->line_number ?? 'N/A',
                    $item->id,
                    $item->category ?? 'N/A',
                    $item->commodity_type ?? 'N/A',
                    $item->relationship_type ?? 'separate',
                    $relatedTo,
                    $item->quantity ?? 1,
                    $item->stack_unit_count ?? 'N/A',
                ];
            })
        );
        
        $this->newLine();
        $this->info("Stack Groupings:");
        
        foreach ($items as $item) {
            if ($item->isInStack()) {
                $baseId = $item->getStackGroup();
                $stackMembers = $item->getStackMembers();
                
                $this->line("Stack Base (ID {$baseId}):");
                foreach ($stackMembers as $member) {
                    $this->line("  - Line {$member->line_number}: {$member->category} (ID: {$member->id}, Relationship: {$member->relationship_type})");
                }
            } elseif ($item->isSeparate()) {
                $this->line("Standalone: Line {$item->line_number} - {$item->category} (ID: {$item->id})");
            }
        }
        
        $this->newLine();
        $this->info("Trailer Analysis:");
        
        $trailers = $items->filter(fn($item) => $item->category === 'trailer');
        foreach ($trailers as $trailer) {
            $this->line("Trailer Line {$trailer->line_number} (ID: {$trailer->id}):");
            $this->line("  Relationship: {$trailer->relationship_type}");
            $this->line("  Related Item ID: " . ($trailer->related_item_id ?? 'N/A'));
            $this->line("  Is Stack Base: " . ($trailer->isStackBase() ? 'YES' : 'NO'));
            $this->line("  Stack Group ID: " . ($trailer->getStackGroup() ?? 'N/A'));
            
            if ($trailer->isConnected() && $trailer->related_item_id) {
                $related = QuotationCommodityItem::find($trailer->related_item_id);
                if ($related) {
                    $this->line("  Connected to: Line {$related->line_number} - {$related->category}");
                    $this->line("  Has truck/truckhead: " . (in_array($related->category, ['truck', 'truckhead']) ? 'YES' : 'NO'));
                }
            }
            
            if ($trailer->isLoadedWith()) {
                $stackMembers = $trailer->getStackMembers();
                $hasTruck = $stackMembers->contains(fn($m) => in_array($m->category, ['truck', 'truckhead']));
                $this->line("  Loaded with items in stack");
                $this->line("  Stack members count: " . $stackMembers->count());
                $this->line("  Stack member categories: " . $stackMembers->pluck('category')->implode(', '));
                $this->line("  Has truck/truckhead in stack: " . ($hasTruck ? 'YES' : 'NO'));
            }
            
            if ($trailer->isSeparate()) {
                $this->line("  Standalone trailer");
            }
        }
        
        $this->newLine();
        $this->info("Relationship Chain Analysis:");
        foreach ($items as $item) {
            if ($item->related_item_id) {
                $related = QuotationCommodityItem::find($item->related_item_id);
                $this->line("Line {$item->line_number} ({$item->category}, ID: {$item->id}) -> {$item->relationship_type} -> Line " . 
                    ($related ? "{$related->line_number} ({$related->category}, ID: {$related->id})" : "{$item->related_item_id} (NOT FOUND)"));
            }
        }
        
        $this->newLine();
    }
    
    private function displaySelectedArticles(QuotationRequest $quotation)
    {
        $this->info("📄 SELECTED ARTICLES");
        
        $articles = QuotationRequestArticle::where('quotation_request_id', $quotation->id)
            ->with('articleCache')
            ->get();
        
        if ($articles->isEmpty()) {
            $this->warn("No articles selected");
            $this->newLine();
            return;
        }
        
        $this->table(
            ['ID', 'Article ID', 'Article Name', 'Commodity Type', 'Quantity', 'Unit Type', 'Event Code', 'Linked Item'],
            $articles->map(function ($article) {
                $notes = json_decode($article->notes ?? '{}', true);
                $eventCode = $notes['event_code'] ?? null;
                $linkedItemId = $notes['commodity_item_id'] ?? null;
                $linkedItem = $linkedItemId ? QuotationCommodityItem::find($linkedItemId) : null;
                $linkedItemDisplay = $linkedItem ? "Line {$linkedItem->line_number}" : ($linkedItemId ? "ID {$linkedItemId}" : 'N/A');
                
                return [
                    $article->id,
                    $article->article_cache_id,
                    $article->articleCache->article_name ?? 'N/A',
                    $article->articleCache->commodity_type ?? 'N/A',
                    $article->quantity,
                    $article->unit_type ?? 'N/A',
                    $eventCode ?? 'N/A',
                    $linkedItemDisplay,
                ];
            })
        );
        
        $this->newLine();
        $this->info("Article Breakdown:");
        $carArticles = $articles->filter(fn($a) => strtoupper($a->articleCache->commodity_type ?? '') === 'CAR');
        $lmArticles = $articles->filter(fn($a) => strtoupper($a->articleCache->commodity_type ?? '') === 'LM CARGO');
        $towingArticles = $articles->filter(fn($a) => 
            ($a->articleCache->is_surcharge ?? false) && 
            (stripos($a->articleCache->article_name ?? '', 'towing') !== false)
        );
        
        $this->line("CAR articles: {$carArticles->count()}");
        $this->line("LM CARGO articles: {$lmArticles->count()}");
        $this->line("Towing articles: {$towingArticles->count()}");
        
        if ($towingArticles->isNotEmpty()) {
            $this->warn("⚠️  Towing articles found:");
            foreach ($towingArticles as $towing) {
                $notes = json_decode($towing->notes ?? '{}', true);
                $linkedItemId = $notes['commodity_item_id'] ?? null;
                $this->line("  - Article ID {$towing->article_cache_id}: Linked to commodity item ID {$linkedItemId}");
            }
        }
        
        $this->newLine();
    }
    
    private function traceTowingLogic(QuotationRequest $quotation)
    {
        $this->info("🔍 TOWING LOGIC TRACE");
        
        $items = $quotation->commodityItems;
        $trailers = $items->filter(fn($item) => $item->category === 'trailer');
        
        if ($trailers->isEmpty()) {
            $this->line("No trailers found in commodity items");
            $this->newLine();
            return;
        }
        
        $resolver = app(CarrierRuleResolver::class);
        
        foreach ($trailers as $trailer) {
            $this->line("--- Trailer Line {$trailer->line_number} (ID: {$trailer->id}) ---");
            
            // Get carrier context
            $carrierId = $quotation->selectedSchedule?->carrier_id;
            $portId = $quotation->selectedSchedule?->pod_id;
            
            if (!$carrierId) {
                $this->warn("  No carrier ID available");
                continue;
            }
            
            // Simulate what resolveSurchargeRules would do
            $this->line("  Carrier ID: {$carrierId}");
            $this->line("  Port ID: " . ($portId ?? 'N/A'));
            $this->line("  Vehicle Category: trailer");
            $this->line("  Commodity Item ID: {$trailer->id}");
            
            // Check relationship
            $this->line("  Relationship Type: {$trailer->relationship_type}");
            $this->line("  Related Item ID: " . ($trailer->related_item_id ?? 'N/A'));
            
            if ($trailer->isConnected() && $trailer->related_item_id) {
                $related = QuotationCommodityItem::find($trailer->related_item_id);
                if ($related) {
                    $this->line("  Connected to: Line {$related->line_number} - {$related->category}");
                    $hasTruck = in_array($related->category, ['truck', 'truckhead']);
                    $this->line("  Is truck/truckhead: " . ($hasTruck ? 'YES' : 'NO'));
                }
            }
            
            if ($trailer->isLoadedWith()) {
                $stackMembers = $trailer->getStackMembers();
                $hasTruck = $stackMembers->contains(fn($m) => in_array($m->category, ['truck', 'truckhead']));
                $this->line("  Stack members: " . $stackMembers->count());
                $this->line("  Has truck/truckhead in stack: " . ($hasTruck ? 'YES' : 'NO'));
            }
            
            // Use reflection to call private method for testing
            $reflection = new \ReflectionClass($resolver);
            $method = $reflection->getMethod('shouldApplyTowing');
            $method->setAccessible(true);
            $shouldApply = $method->invoke($resolver, 'trailer', $trailer->id);
            
            $this->line("  ✅ shouldApplyTowing() result: " . ($shouldApply ? 'YES (towing needed)' : 'NO (no towing)'));
            
            // Check carrier_rule_meta
            $meta = $trailer->carrier_rule_meta ?? [];
            $surchargeEvents = $meta['surcharge_events'] ?? [];
            $towingEvents = collect($surchargeEvents)->filter(fn($e) => 
                ($e['event_code'] ?? '') === 'TOWING' || ($e['event_code'] ?? '') === 'TOWING_WAF'
            );
            
            $this->line("  Carrier Rule Meta - Surcharge Events: " . count($surchargeEvents));
            if ($towingEvents->isNotEmpty()) {
                $this->warn("  ⚠️  TOWING event found in carrier_rule_meta:");
                foreach ($towingEvents as $event) {
                    $this->line("    - Event Code: {$event['event_code']}");
                    $this->line("    - Quantity: {$event['qty']}");
                    $this->line("    - Amount: {$event['amount']}");
                }
            } else {
                $this->line("  ✓ No TOWING event in carrier_rule_meta");
            }
            
            $this->newLine();
        }
    }
    
    private function analyzeArticleSelection(QuotationRequest $quotation)
    {
        $this->info("🎯 ARTICLE SELECTION ANALYSIS");
        
        // Get what articles should be available
        $articles = \App\Models\RobawsArticleCache::forQuotationContext($quotation)->get();
        
        $this->line("Articles available via scopeForQuotationContext: {$articles->count()}");
        
        $carArticles = $articles->filter(fn($a) => strtoupper($a->commodity_type ?? '') === 'CAR');
        $lmArticles = $articles->filter(fn($a) => strtoupper($a->commodity_type ?? '') === 'LM CARGO');
        $towingArticles = $articles->filter(fn($a) => $a->is_surcharge ?? false);
        
        $this->line("CAR articles available: {$carArticles->count()}");
        $this->line("LM CARGO articles available: {$lmArticles->count()}");
        $this->line("Surcharge articles available: {$towingArticles->count()}");
        
        // Check commodity types extracted
        $items = $quotation->commodityItems;
        $commodityTypes = [];
        
        foreach ($items as $item) {
            $types = \App\Models\QuotationCommodityItem::normalizeCommodityTypes($item);
            if (!empty($types)) {
                $commodityTypes = array_merge($commodityTypes, $types);
            }
        }
        
        $commodityTypes = array_unique($commodityTypes);
        $this->line("Commodity types extracted from items: " . implode(', ', $commodityTypes));
        
        $this->newLine();
        
        // Step 6: Recommendations
        $this->displayRecommendations($quotation);
    }
    
    private function displayRecommendations(QuotationRequest $quotation)
    {
        $this->info("💡 RECOMMENDATIONS");
        
        $items = $quotation->commodityItems;
        $trailers = $items->filter(fn($item) => $item->category === 'trailer');
        $towingArticles = QuotationRequestArticle::where('quotation_request_id', $quotation->id)
            ->with('articleCache')
            ->get()
            ->filter(fn($a) => 
                ($a->articleCache->is_surcharge ?? false) && 
                (stripos($a->articleCache->article_name ?? '', 'towing') !== false)
            );
        
        if ($towingArticles->isNotEmpty()) {
            foreach ($trailers as $trailer) {
                $stackMembers = $trailer->getStackMembers();
                $hasTruck = $stackMembers->contains(fn($m) => in_array($m->category, ['truck', 'truckhead']));
                
                if ($hasTruck) {
                    $this->warn("⚠️  Trailer Line {$trailer->line_number} has truck in stack but towing article exists.");
                    $this->line("   Action: Reprocess commodity item to remove towing article.");
                    $this->line("   Command: php artisan tinker --execute=\"");
                    $this->line("     \\\$item = \\\App\\\Models\\\QuotationCommodityItem::find({$trailer->id});");
                    $this->line("     app(\\\App\\\Services\\\CarrierRules\\\CarrierRuleIntegrationService::class)->processCommodityItem(\\\$item);");
                    $this->line("   \"");
                }
            }
        }
        
        $articles = QuotationRequestArticle::where('quotation_request_id', $quotation->id)->get();
        $carArticles = $articles->filter(fn($a) => strtoupper($a->articleCache->commodity_type ?? '') === 'CAR');
        $lmArticles = $articles->filter(fn($a) => strtoupper($a->articleCache->commodity_type ?? '') === 'LM CARGO');
        
        $hasStacks = $items->contains(fn($i) => $i->isInStack());
        if ($hasStacks && $lmArticles->isEmpty()) {
            $this->warn("⚠️  Quotation has stacks but no LM CARGO articles found.");
            $this->line("   Possible causes:");
            $this->line("   1. No LM CARGO articles exist in database for this carrier/route");
            $this->line("   2. Article mappings don't include LM articles");
            $this->line("   3. ALLOWLIST strategy is too restrictive");
        }
        
        $this->newLine();
    }
}
