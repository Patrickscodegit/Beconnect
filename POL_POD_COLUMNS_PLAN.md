# Add POL and POD Columns to Article Table (Schedule Format Match)

## Current Situation

**Article Name Pattern:**
```
"FCL - Conakry (ANR) - Guinee, 40ft HC seafreight"
       ↓        ↓       ↓
     POD      POL    Country
```

**Schedule Port Format (from screenshot):**
```
"Antwerp, Belgium (ANR)"
 ↓       ↓        ↓
Name  Country   Code
```

**Current Table:**
- POL Terminal: (empty) - terminal code from Robaws metadata
- **Missing:** POL in schedule format (e.g., "Antwerp, Belgium (ANR)")
- **Missing:** POD in schedule format (e.g., "Conakry, Guinea (CKY)")

## Goal

Add **two new columns** matching the exact schedule port notation:

1. **POL** - Port of Loading in format: `"Name, Country (CODE)"`
2. **POD** - Port of Discharge in format: `"Name, Country (CODE)"`

**Example:**
- POL: "Antwerp, Belgium (ANR)"
- POD: "Conakry, Guinea (CKY)"

## Implementation Plan

### Phase 1: Database Schema

Create migration to add `pol_code` and `pod_name` columns.

**File:** `database/migrations/2025_10_16_XXXXXX_add_pol_pod_to_robaws_articles_cache.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            // POL in schedule format: "Antwerp, Belgium (ANR)"
            $table->string('pol_code')->nullable()->after('pol_terminal');
            
            // POD in schedule format: "Conakry, Guinea (CKY)"
            $table->string('pod_name')->nullable()->after('pol_code');
            
            // Indexes for filtering
            $table->index('pol_code');
            $table->index('pod_name');
        });
    }

    public function down(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            $table->dropIndex(['pol_code']);
            $table->dropIndex(['pod_name']);
            $table->dropColumn(['pol_code', 'pod_name']);
        });
    }
};
```

### Phase 2: Update Model

**File:** `app/Models/RobawsArticleCache.php`

```php
protected $fillable = [
    // ... existing fields ...
    'pol_terminal',
    'pol_code',      // NEW: "Antwerp, Belgium (ANR)"
    'pod_name',      // NEW: "Conakry, Guinea (CKY)"
    // ...
];
```

### Phase 3: Enhance Extraction Logic

Update `RobawsArticleProvider` to extract POL/POD and format them to match schedules.

**File:** `app/Services/Robaws/RobawsArticleProvider.php`

**Update `extractMetadataFromArticle()` method:**

```php
private function extractMetadataFromArticle(RobawsArticleCache $article): array
{
    $metadata = [];
    
    // Extract shipping line from description (existing)
    $metadata['shipping_line'] = $this->extractShippingLineFromDescription(
        $article->article_name
    );
    
    // Extract service type from description (existing)
    $metadata['service_type'] = $this->extractServiceTypeFromDescription(
        $article->article_name
    );
    
    // NEW: Extract POL code from parentheses and format as schedule: (ANR) → "Antwerp, Belgium (ANR)"
    if (preg_match('/\(([A-Z]{3})\)/', $article->article_name, $matches)) {
        $polCode = $matches[1]; // e.g., "ANR"
        
        // Lookup POL in ports table
        $polPort = \App\Models\Port::where('code', $polCode)->first();
        
        if ($polPort) {
            // Format as schedule: "Antwerp, Belgium (ANR)"
            $metadata['pol_code'] = $polPort->name . ', ' . $polPort->country . ' (' . $polPort->code . ')';
            
            // Also populate pol_terminal if available
            if ($polPort->terminal_code ?? null) {
                $metadata['pol_terminal'] = $polPort->terminal_code;
            }
        } else {
            // Fallback: use extracted code if port not found
            $metadata['pol_code'] = $polCode;
        }
    } else {
        // Fallback to old extraction method if no parentheses code found
        $metadata['pol_terminal'] = $this->extractPolTerminalFromDescription(
            $article->article_name
        );
    }
    
    // NEW: Extract POD name and format as schedule: "Conakry" → "Conakry, Guinea (CKY)"
    if (preg_match('/(?:FCL|RORO)\s*-\s*([A-Za-z\s]+?)\s*\([A-Z]{3}\)/', $article->article_name, $matches)) {
        $podName = trim($matches[1]); // e.g., "Conakry"
        
        // Lookup POD in ports table (case-insensitive, fuzzy match)
        $podPort = \App\Models\Port::where('name', 'LIKE', '%' . $podName . '%')->first();
        
        if ($podPort) {
            // Format as schedule: "Conakry, Guinea (CKY)"
            $metadata['pod_name'] = $podPort->name . ', ' . $podPort->country . ' (' . $podPort->code . ')';
        } else {
            // Fallback: use extracted name if port not found
            $metadata['pod_name'] = $podName;
        }
    }
    
    // Cannot determine parent status from description alone
    $metadata['is_parent_item'] = null;
    
    // Cannot extract dates from description
    $metadata['update_date'] = null;
    $metadata['validity_date'] = null;
    $metadata['article_info'] = 'Extracted from description (API unavailable)';
    
    return $metadata;
}
```

### Phase 4: Update Filament Table Display

**File:** `app/Filament/Resources/RobawsArticleResource.php`

**Add POL and POD columns after service_type:**

```php
->columns([
    Tables\Columns\TextColumn::make('article_code')
        ->searchable()
        ->sortable()
        ->toggleable(),
        
    Tables\Columns\TextColumn::make('article_name')
        ->searchable()
        ->limit(50)
        ->tooltip(function (TextColumn $column): ?string {
            $state = $column->getState();
            return strlen($state) > 50 ? $state : null;
        }),
        
    Tables\Columns\TextColumn::make('unit_price')
        ->money('EUR')
        ->sortable(),
    
    Tables\Columns\BadgeColumn::make('shipping_line')
        ->label('Shipping Line')
        ->formatStateUsing(fn ($state) => $state ?: 'Not specified')
        ->color(fn ($state) => $state ? 'primary' : 'gray')
        ->toggleable(),
        
    Tables\Columns\BadgeColumn::make('service_type')
        ->label('Service Type')
        ->formatStateUsing(fn ($state) => $state ?: 'Not specified')
        ->color(fn ($state) => $state ? 'success' : 'gray')
        ->toggleable(),
    
    // NEW: POL in schedule format
    Tables\Columns\TextColumn::make('pol_code')
        ->label('POL')
        ->formatStateUsing(fn ($state) => $state ?: 'N/A')
        ->color(fn ($state) => $state ? 'success' : 'gray')
        ->tooltip('Port of Loading (matches schedule format)')
        ->searchable()
        ->toggleable(),
    
    // EXISTING: POL Terminal
    Tables\Columns\TextColumn::make('pol_terminal')
        ->label('POL Terminal')
        ->formatStateUsing(fn ($state) => $state ?: 'N/A')
        ->color(fn ($state) => $state ? 'primary' : 'gray')
        ->tooltip(fn ($state) => $state ? null : 'Not available in Robaws')
        ->toggleable(),
    
    // NEW: POD in schedule format
    Tables\Columns\TextColumn::make('pod_name')
        ->label('POD')
        ->formatStateUsing(fn ($state) => $state ?: 'N/A')
        ->color(fn ($state) => $state ? 'info' : 'gray')
        ->tooltip('Port of Discharge (matches schedule format)')
        ->searchable()
        ->toggleable(),
    
    Tables\Columns\IconColumn::make('is_parent_item')
        ->boolean()
        ->label('Parent')
        ->tooltip('Parent item status from Robaws API')
        ->toggleable(),
    
    // ... rest of columns ...
])
```

**Add filters:**

```php
->filters([
    // ... existing filters ...
    
    Tables\Filters\SelectFilter::make('pol_code')
        ->label('POL')
        ->options(fn () => RobawsArticleCache::distinct()
            ->whereNotNull('pol_code')
            ->pluck('pol_code', 'pol_code')
            ->toArray()),
    
    Tables\Filters\SelectFilter::make('pod_name')
        ->label('POD')
        ->options(fn () => RobawsArticleCache::distinct()
            ->whereNotNull('pod_name')
            ->pluck('pod_name', 'pod_name')
            ->toArray()),
])
```

## Expected Results

**For article "FCL - Conakry (ANR) - Guinee, 40ft HC seafreight":**

**After re-sync:**
- **POL:** `Antwerp, Belgium (ANR)` ← **MATCHES SCHEDULE FORMAT!**
- **POL Terminal:** `N/A`
- **POD:** `Conakry, Guinea (CKY)` ← **MATCHES SCHEDULE FORMAT!**

**Table Display:**
| Article Name | Service Type | **POL** | POL Terminal | **POD** | Parent |
|-------------|-------------|---------|--------------|---------|--------|
| FCL - Conakry (ANR)... | FCL EXPORT | **Antwerp, Belgium (ANR)** | N/A | **Conakry, Guinea (CKY)** | ✓ |

## Format Consistency

**Schedule Format (from `resources/views/schedules/index.blade.php`):**
```blade
{{ $port->name }}, {{ $port->country }} ({{ $port->code }})
```

**Article Table Format (NEW):**
```php
$polPort->name . ', ' . $polPort->country . ' (' . $polPort->code . ')'
```

**Result:** ✅ **Exact match with schedule display!**

## Testing Instructions

### 1. Run Migration
```bash
php artisan migrate
```

### 2. Test Extraction via Tinker
```bash
php artisan tinker

$article = App\Models\RobawsArticleCache::where('article_name', 'LIKE', '%Conakry (ANR)%')->first();
$provider = app(App\Services\Robaws\RobawsArticleProvider::class);
$metadata = $provider->syncArticleMetadata($article->id);
$article->refresh();

echo "POL: " . ($article->pol_code ?? 'N/A') . "\n";
echo "POL Terminal: " . ($article->pol_terminal ?? 'N/A') . "\n";
echo "POD: " . ($article->pod_name ?? 'N/A') . "\n";
```

**Expected Output:**
```
POL: Antwerp, Belgium (ANR)
POL Terminal: N/A
POD: Conakry, Guinea (CKY)
```

### 3. Verify in Filament
1. Go to **Filament Admin → Quotation System → Article Cache**
2. Search for "conakry"
3. Check columns match schedule format

## Benefits

✅ **Schedule Format Match:** POL/POD display exactly like schedules ("Name, Country (CODE)")  
✅ **Consistency:** Same port notation across entire system  
✅ **User Familiarity:** Users see familiar format from schedules  
✅ **Searchable:** Can search by port name, country, or code  
✅ **Database Validated:** Port info comes from official ports table  

## Files to Modify

1. `database/migrations/2025_10_16_XXXXXX_add_pol_pod_to_robaws_articles_cache.php` (new)
2. `app/Models/RobawsArticleCache.php`
3. `app/Services/Robaws/RobawsArticleProvider.php`
4. `app/Filament/Resources/RobawsArticleResource.php`

