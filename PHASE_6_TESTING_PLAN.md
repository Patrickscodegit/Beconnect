# Phase 6: Testing & Validation Plan

## 🧪 **CAREFUL TESTING APPROACH**

Testing in 3 stages to ensure quality before full extraction.

---

## **STAGE 1: Small Batch Test (10 offers)**

### **Setup:**
```bash
# Set environment variable for testing
# Add to .env:
ROBAWS_ARTICLE_EXTRACTION_LIMIT=10
```

### **Run:**
```bash
php artisan robaws:sync-articles
```

### **Expected Results:**
- ~50-100 articles extracted (assuming 5-10 articles per offer)
- ~5-10 parent-child relationships detected
- No errors or exceptions
- All detection methods working

### **Verify:**
```php
// Check article count
$count = RobawsArticleCache::count();
echo "Articles extracted: $count";

// Check parent-child links
$relationships = DB::table('article_children')->count();
echo "Relationships created: $relationships";

// Check service type detection
$roroCount = RobawsArticleCache::whereJsonContains('applicable_services', 'RORO_IMPORT')->count();
echo "RORO articles: $roroCount";

// Check article codes parsed
$withCodes = RobawsArticleCache::whereNotNull('article_code')->count();
echo "Articles with codes: $withCodes";

// Check parent articles
$parents = RobawsArticleCache::where('is_parent_article', true)->count();
echo "Parent articles: $parents";

// Check quantity tiers
$tiered = RobawsArticleCache::where('max_quantity', '>', 1)->count();
echo "Articles with quantity tiers: $tiered";
```

### **What to Look For:**
- ✅ Articles have article_code extracted
- ✅ Service types populated
- ✅ Categories assigned correctly
- ✅ Parent-child links make sense
- ✅ No articles flagged for manual review unnecessarily

### **If Issues Found:**
- Review extraction logs
- Check sample article descriptions
- Adjust detection patterns if needed
- Re-run with same 10 offers

---

## **STAGE 2: Medium Batch Test (50 offers)**

### **Setup:**
```bash
# Update .env:
ROBAWS_ARTICLE_EXTRACTION_LIMIT=50
```

### **Run:**
```bash
# Clear previous test data
php artisan tinker --execute="App\Models\RobawsArticleCache::truncate(); DB::table('article_children')->truncate();"

# Run extraction
php artisan robaws:sync-articles
```

### **Expected Results:**
- ~250-500 articles extracted
- ~25-50 parent-child relationships
- Better pattern validation
- Edge cases detected

### **Verify:**
```php
// More detailed analysis
$articles = RobawsArticleCache::all();

// Service type distribution
$byService = $articles->groupBy(function($a) {
    return $a->applicable_services[0] ?? 'none';
})->map->count();
print_r($byService->toArray());

// Category distribution
$byCategory = $articles->groupBy('category')->map->count();
print_r($byCategory->toArray());

// Carrier distribution
$withCarriers = $articles->filter(function($a) {
    return !empty($a->applicable_carriers);
})->count();
echo "Articles with carriers: $withCarriers";

// Review flagged articles
$needReview = RobawsArticleCache::where('requires_manual_review', true)->get();
foreach ($needReview as $article) {
    echo "Review: {$article->article_name}\n";
}
```

### **Test Specific Cases:**

#### **Test 1: GANRLAC Bundle**
```php
$ganrlac = RobawsArticleCache::where('article_code', 'GANRLAC')->first();

if ($ganrlac) {
    echo "GANRLAC found: {$ganrlac->article_name}\n";
    echo "Is parent: " . ($ganrlac->is_parent_article ? 'YES' : 'NO') . "\n";
    echo "Children count: " . $ganrlac->children->count() . "\n";
    
    foreach ($ganrlac->children as $child) {
        echo "  - {$child->article_name}\n";
    }
}
```

#### **Test 2: CONSOL Formula**
```php
$consolArticles = RobawsArticleCache::whereNotNull('pricing_formula')->get();

foreach ($consolArticles as $article) {
    echo "Formula article: {$article->article_name}\n";
    print_r($article->pricing_formula);
    
    // Test calculation
    $price = $article->calculateFormulaPrice(['ocean_freight' => 1600]);
    echo "Calculated price: €{$price}\n";
}
```

#### **Test 3: Quantity Tiers**
```php
$tieredArticles = RobawsArticleCache::where('max_quantity', '>', 1)->get();

foreach ($tieredArticles as $article) {
    echo "{$article->tier_label}: {$article->article_name} (qty: {$article->min_quantity}-{$article->max_quantity})\n";
}
```

#### **Test 4: Customer Type Filtering**
```php
$cibArticles = RobawsArticleCache::where('customer_type', 'CIB')->get();

echo "CIB-specific articles: " . $cibArticles->count() . "\n";
foreach ($cibArticles as $article) {
    echo "  - {$article->article_name}\n";
}
```

### **Performance Check:**
```php
// Check sync log
$lastSync = App\Models\RobawsSyncLog::latest()->first();
echo "Sync duration: " . $lastSync->completed_at->diffInSeconds($lastSync->started_at) . " seconds\n";
echo "Articles synced: " . $lastSync->synced_count . "\n";
echo "Status: " . $lastSync->status . "\n";
```

---

## **STAGE 3: Full Extraction (500 offers)**

### **Setup:**
```bash
# Update .env:
ROBAWS_ARTICLE_EXTRACTION_LIMIT=500

# OR remove the limit to use default
# (comment out the line)
```

### **Run:**
```bash
# Clear test data
php artisan tinker --execute="App\Models\RobawsArticleCache::truncate(); DB::table('article_children')->truncate();"

# Run full extraction
php artisan robaws:sync-articles
```

### **Expected Results:**
- ~3,000-7,000 articles extracted
- ~300-700 parent-child relationships
- Complete article catalog
- Production-ready data

### **Final Validation:**
```php
$stats = [
    'total_articles' => RobawsArticleCache::count(),
    'parent_articles' => RobawsArticleCache::where('is_parent_article', true)->count(),
    'surcharges' => RobawsArticleCache::where('is_surcharge', true)->count(),
    'with_codes' => RobawsArticleCache::whereNotNull('article_code')->count(),
    'with_carriers' => RobawsArticleCache::whereNotNull('applicable_carriers')->count(),
    'with_services' => RobawsArticleCache::whereNotNull('applicable_services')->count(),
    'needs_review' => RobawsArticleCache::where('requires_manual_review', true)->count(),
    'relationships' => DB::table('article_children')->count(),
];

print_r($stats);
```

---

## **STAGE 4: End-to-End Quotation Test**

### **Create Sample Quotation:**
```php
use App\Models\QuotationRequest;
use App\Models\RobawsArticleCache;
use App\Services\OfferTemplateService;

// 1. Create quotation request
$quotation = QuotationRequest::create([
    'request_number' => QuotationRequest::generateRequestNumber(),
    'source' => 'customer',
    'requester_type' => 'customer',
    'requester_email' => 'test@example.com',
    'requester_name' => 'John Doe',
    'requester_company' => 'Test Company',
    'service_type' => 'RORO_IMPORT',
    'trade_direction' => 'IMPORT',
    'routing' => [
        'pol' => 'Antwerp',
        'pod' => 'Lagos',
    ],
    'cargo_details' => [
        'type' => 'car',
        'quantity' => 1,
    ],
    'customer_role' => 'HOLLANDICO',
    'customer_type' => 'GENERAL',
    'vat_rate' => 21.00,
    'pricing_currency' => 'EUR',
    'status' => 'processing',
]);

echo "✓ Quotation created: {$quotation->request_number}\n";

// 2. Find GANRLAC article
$ganrlac = RobawsArticleCache::where('article_code', 'GANRLAC')->first();

if ($ganrlac) {
    // 3. Add article (should auto-add children)
    $quotation->addArticle($ganrlac, 1);
    echo "✓ Added GANRLAC article\n";
    
    // 4. Check articles
    $articleCount = $quotation->getArticleCount();
    echo "✓ Articles in quotation: {$articleCount}\n";
    
    // 5. Check totals
    echo "  Subtotal: {$quotation->formatted_subtotal}\n";
    echo "  VAT: €" . number_format($quotation->vat_amount, 2) . "\n";
    echo "  Total: {$quotation->formatted_total}\n";
}

// 6. Apply templates
$templateService = new OfferTemplateService();
$templateService->applyTemplates($quotation);

echo "✓ Templates applied\n";
echo "  Intro text preview: " . substr($quotation->intro_text, 0, 100) . "...\n";
echo "  End text preview: " . substr($quotation->end_text, 0, 100) . "...\n";

// 7. Verify everything
$tests = [
    'Quotation created' => $quotation->id > 0,
    'Request number generated' => !empty($quotation->request_number),
    'Articles added' => $quotation->articles->count() > 0,
    'Children auto-added' => $quotation->articles->where('pivot.item_type', 'child')->count() > 0,
    'Subtotal calculated' => $quotation->subtotal > 0,
    'VAT calculated' => $quotation->vat_amount > 0,
    'Total calculated' => $quotation->total_incl_vat > 0,
    'Intro template applied' => !empty($quotation->intro_text),
    'End template applied' => !empty($quotation->end_text),
];

echo "\n=== END-TO-END TEST RESULTS ===\n";
foreach ($tests as $test => $passed) {
    echo ($passed ? '✓' : '✗') . " {$test}\n";
}
```

---

## **SUCCESS CRITERIA**

### **Stage 1 (10 offers):**
- [ ] No errors during extraction
- [ ] At least 50 articles extracted
- [ ] At least 5 parent-child relationships
- [ ] Article codes parsed for >80% of articles
- [ ] Service types detected for >70% of articles

### **Stage 2 (50 offers):**
- [ ] No errors during extraction
- [ ] At least 250 articles extracted
- [ ] GANRLAC bundle found with children
- [ ] CONSOL formula articles found (if any)
- [ ] Quantity tiers detected (2-pack, 3-pack, etc.)
- [ ] Customer type filtering works

### **Stage 3 (500 offers):**
- [ ] No errors during extraction
- [ ] At least 3,000 articles extracted
- [ ] At least 300 parent-child relationships
- [ ] <20% of articles require manual review
- [ ] All service types represented
- [ ] Multiple carriers detected

### **Stage 4 (End-to-End):**
- [ ] Quotation creation successful
- [ ] Article addition successful
- [ ] Child articles auto-added
- [ ] Totals calculated correctly
- [ ] Templates rendered correctly
- [ ] All 9 tests pass

---

## **ROLLBACK PLAN**

If any stage fails:

```bash
# Rollback articles
php artisan tinker --execute="App\Models\RobawsArticleCache::truncate(); DB::table('article_children')->truncate();"

# Check logs
tail -f storage/logs/laravel.log

# Review sync logs
php artisan tinker --execute="App\Models\RobawsSyncLog::latest()->first()->toArray()"
```

---

## **READY TO START?**

Begin with Stage 1:
1. Add `ROBAWS_ARTICLE_EXTRACTION_LIMIT=10` to `.env`
2. Run `php artisan robaws:sync-articles`
3. Verify results
4. Proceed to Stage 2 if successful

**Let's start Stage 1!**

