# Customer Portal Smart Article Selection - Implementation Plan v2.0

## Executive Summary

**Goal:** Enable customers to see and select smart article suggestions DURING quotation creation with tier-based pricing visibility.

**Approach:** Auto-save draft quotation with Livewire for real-time article selection.

**Timeline:** ~3-4 days implementation + testing

**Risk Level:** MEDIUM - Requires Livewire conversion of create form and auto-save functionality.

---

## Revised Approach: Auto-Save Draft with Real-Time Article Selection

### Current State ❌
- Customer fills HTML form
- Submits entire form at once
- Creates quotation on submit
- Redirects to show page (view only)
- **NO article selection during creation**
- Admin processes manually in Filament

### Target State ✅ **SEAMLESS CREATION EXPERIENCE**
- Customer starts filling form
- **Auto-creates draft quotation** (status: 'draft')
- As customer fills POL/POD/Schedule → **Articles appear in real-time**
- Customer **selects articles during creation**
- Sees **tier-based pricing** update live
- Clicks "Submit for Review" → Status changes to 'pending'
- **After submission:** Can view/edit on show page until admin approves
- **After approval:** View only + Duplicate option

---

## Visual Mockup: Creation Flow with Live Article Selection

```
┌─────────────────────────────────────────────────────────────────┐
│ Request a Quotation                                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│ 🚢 Route & Service                                               │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ POL: [Antwerp_________]  ← Customer types, auto-saves       │ │
│ │ POD: [Conakry_________]  ← Customer types, auto-saves       │ │
│ │ Service: [RORO Export▼]                                     │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                   │
│ 📅 Select Sailing (Optional)                                     │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Schedule: [Sallaum Lines - ETD: Nov 5, 2025 ▼]             │ │
│ │          ↑ When selected, articles appear below             │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                   │
│ 📦 Cargo Information                                             │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Commodity: [Cars ▼]                                         │ │
│ │ Description: [1x Toyota Corolla 2020______________]         │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                   │
├─────────────────────────────────────────────────────────────────┤
│ 💡 Suggested Services & Articles  ← APPEARS WHEN POL+POD+SCHEDULE SET │
├─────────────────────────────────────────────────────────────────┤
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ 🎯 100% match - SANRCONCAR                     [Add Article] │ │
│ │ €787.00 / unit (Tier B pricing)                             │ │
│ │ ✓ Route: Antwerp → Conakry                                  │ │
│ │ ✓ Carrier: SALLAUM LINES  ✓ Commodity: Car                 │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                   │
│ ✅ Selected Articles (1)                                         │
│ SANRCONCAR - €787.00 × 1 = €787.00                [Remove]      │
│                                                                   │
│ 💰 Pricing Summary                                               │
│ Subtotal: €787.00  |  VAT: €165.27  |  Total: €952.27          │
│                                                                   │
├─────────────────────────────────────────────────────────────────┤
│                   [Save Draft]  [Submit for Review]              │
└─────────────────────────────────────────────────────────────────┘
```

**Key Benefits:**
- ✅ Customer sees everything before submitting
- ✅ No need to edit after creation
- ✅ Real-time pricing feedback
- ✅ Seamless single-flow experience

---

## Technical Architecture

### Auto-Save Draft Strategy

**Approach:** Convert customer quotation create form to Livewire component

#### Why Livewire?
1. **Real-time updates** - POL/POD/Schedule changes trigger article reload
2. **Auto-save** - Draft quotation created on mount, updated on field changes
3. **State management** - Articles, pricing, all managed in component
4. **No page reload** - Smooth UX

#### Draft Quotation Lifecycle

```
┌──────────────────────────────────────────────────────────────┐
│ 1. Customer lands on create page                             │
│    → Livewire component mounts                               │
│    → Creates DRAFT quotation with status: 'draft'            │
│    → Stores quotation_id in component state                  │
├──────────────────────────────────────────────────────────────┤
│ 2. Customer fills POL                                        │
│    → Auto-saves to draft quotation                           │
│    → Checks if POL+POD+Schedule exist                        │
├──────────────────────────────────────────────────────────────┤
│ 3. Customer fills POD                                        │
│    → Auto-saves to draft quotation                           │
│    → Checks if POL+POD+Schedule exist                        │
├──────────────────────────────────────────────────────────────┤
│ 4. Customer selects Schedule                                 │
│    → Auto-saves to draft quotation                           │
│    → POL+POD+Schedule NOW COMPLETE ✓                         │
│    → Triggers SmartArticleSelector to load suggestions       │
│    → Articles appear on page                                 │
├──────────────────────────────────────────────────────────────┤
│ 5. Customer selects Commodity = 'Cars'                       │
│    → Auto-saves commodity to draft                           │
│    → Articles reload with commodity filter                   │
│    → Only CAR articles show now                              │
├──────────────────────────────────────────────────────────────┤
│ 6. Customer adds article (SANRCONCAR)                        │
│    → Attaches to draft quotation                             │
│    → Calculates totals with tier pricing                     │
│    → Shows pricing summary                                   │
├──────────────────────────────────────────────────────────────┤
│ 7. Customer clicks "Submit for Review"                       │
│    → Changes status: 'draft' → 'pending'                     │
│    → Sends notification to Belgaco team                      │
│    → Redirects to show page                                  │
└──────────────────────────────────────────────────────────────┘
```

---

## Implementation Details

### Phase 1: Create Livewire Quotation Creation Component

#### 1.1 Create New Livewire Component

**Command to run:**
```bash
php artisan make:livewire Customer/QuotationCreator
```

**File:** `app/Http/Livewire/Customer/QuotationCreator.php`

**Component structure:**
```php
<?php

namespace App\Http\Livewire\Customer;

use Livewire\Component;
use Livewire\WithFileUploads;
use App\Models\QuotationRequest;
use App\Models\Port;
use App\Models\ShippingSchedule;
use App\Models\ShippingCarrier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QuotationCreator extends Component
{
    use WithFileUploads;
    
    // Quotation ID (draft created on mount)
    public ?int $quotationId = null;
    public ?QuotationRequest $quotation = null;
    
    // Form fields
    public $pol = '';
    public $pod = '';
    public $por = '';
    public $fdest = '';
    public $service_type = '';
    public $commodity_type = '';
    public $cargo_description = '';
    public $special_requirements = '';
    public $selected_schedule_id = null;
    public $customer_reference = '';
    
    // File uploads
    public $supporting_files = [];
    
    // State
    public bool $showArticles = false;
    public bool $loading = false;
    
    // Listen for article selection events
    protected $listeners = [
        'articleAdded' => 'handleArticleAdded',
        'articleRemoved' => 'handleArticleRemoved',
    ];
    
    public function mount($intakeId = null)
    {
        // Create draft quotation immediately
        $this->createDraftQuotation();
        
        // If coming from intake, prefill data
        if ($intakeId) {
            $this->prefillFromIntake($intakeId);
        }
    }
    
    protected function createDraftQuotation()
    {
        $user = auth()->user();
        
        DB::transaction(function () use ($user) {
            $this->quotation = QuotationRequest::create([
                'status' => 'draft',
                'source' => 'customer',
                'requester_type' => 'customer',
                'contact_email' => $user->email,
                'contact_name' => $user->name,
                'contact_phone' => $user->phone,
                'client_email' => $user->email,
                'client_name' => $user->name,
                'client_tel' => $user->phone,
                'pricing_tier_id' => $user->pricing_tier_id ?? $this->getDefaultPricingTierId(),
                'customer_role' => $user->customer_role ?? 'RORO',
                'vat_rate' => 21.00,
                'pricing_currency' => 'EUR',
            ]);
            
            $this->quotationId = $this->quotation->id;
        });
        
        Log::info('Draft quotation created for customer', [
            'quotation_id' => $this->quotationId,
            'user_email' => $user->email,
        ]);
    }
    
    protected function getDefaultPricingTierId(): ?int
    {
        // Default to Tier C (most expensive) for customers without assigned tier
        return \App\Models\PricingTier::where('code', 'C')->first()?->id;
    }
    
    // Auto-save when fields change
    public function updated($propertyName)
    {
        // Save to draft quotation
        if ($this->quotation) {
            $this->quotation->update([
                'pol' => $this->pol,
                'pod' => $this->pod,
                'por' => $this->por,
                'fdest' => $this->fdest,
                'service_type' => $this->service_type,
                'commodity_type' => $this->commodity_type,
                'cargo_description' => $this->cargo_description,
                'special_requirements' => $this->special_requirements,
                'selected_schedule_id' => $this->selected_schedule_id,
                'customer_reference' => $this->customer_reference,
            ]);
            
            // Check if we should show articles
            $this->showArticles = !empty($this->pol) && 
                                 !empty($this->pod) && 
                                 !empty($this->selected_schedule_id);
            
            // Emit event to SmartArticleSelector to reload
            if ($this->showArticles) {
                $this->emit('quotationUpdated');
            }
        }
    }
    
    public function handleArticleAdded($articleId)
    {
        // Recalculate totals
        $this->quotation->fresh()->calculateTotals();
        $this->quotation = $this->quotation->fresh();
    }
    
    public function handleArticleRemoved($articleId)
    {
        // Recalculate totals
        $this->quotation->fresh()->calculateTotals();
        $this->quotation = $this->quotation->fresh();
    }
    
    public function submit()
    {
        // Validate
        $this->validate([
            'pol' => 'required|string|max:255',
            'pod' => 'required|string|max:255',
            'service_type' => 'required|string',
            'cargo_description' => 'required|string',
        ]);
        
        // Change status from 'draft' to 'pending'
        $this->quotation->update([
            'status' => 'pending',
        ]);
        
        // Send notification to admin
        // ... notification logic
        
        // Redirect to show page
        return redirect()
            ->route('customer.quotations.show', $this->quotation)
            ->with('success', 'Quotation submitted for review! Our team will respond shortly.');
    }
    
    public function saveDraft()
    {
        // Already auto-saving, just show message
        session()->flash('message', 'Draft saved successfully!');
    }
    
    public function render()
    {
        $polPorts = Port::europeanOrigins()->orderBy('name')->get();
        $podPorts = Port::withActivePodSchedules()->orderBy('name')->get();
        $schedules = ShippingSchedule::where('is_active', true)
            ->orderBy('etd')
            ->get();
        $serviceTypes = config('quotation.service_types', []);
        
        return view('livewire.customer.quotation-creator', compact(
            'polPorts',
            'podPorts',
            'schedules',
            'serviceTypes'
        ));
    }
}
```

---

#### 1.2 Create Livewire View

**File:** `resources/views/livewire/customer/quotation-creator.blade.php`

**Structure:**
```blade
<div>
    {{-- Auto-save indicator --}}
    <div class="mb-4 flex justify-between items-center">
        <div>
            <span class="text-sm text-gray-500">
                <i class="fas fa-cloud mr-1" 
                   :class="{'fa-check text-green-500': !$wire.isLoading, 'fa-sync fa-spin text-blue-500': $wire.isLoading}">
                </i>
                <span wire:loading.remove>Draft saved automatically</span>
                <span wire:loading>Saving...</span>
            </span>
        </div>
        @if($quotationId)
            <div class="text-xs text-gray-400">
                Draft ID: QR-{{ str_pad($quotationId, 4, '0', STR_PAD_LEFT) }}
            </div>
        @endif
    </div>

    {{-- Route & Service Section --}}
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">
            <i class="fas fa-route mr-2"></i>Route & Service
        </h2>
        
        <div class="grid grid-cols-2 gap-4">
            {{-- POL --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Port of Loading (POL) *
                </label>
                <input type="text" 
                       wire:model.debounce.500ms="pol"
                       class="form-input w-full px-4 py-3 rounded-lg border"
                       placeholder="e.g., Antwerp"
                       required>
                @error('pol') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
            </div>
            
            {{-- POD --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Port of Discharge (POD) *
                </label>
                <input type="text" 
                       wire:model.debounce.500ms="pod"
                       class="form-input w-full px-4 py-3 rounded-lg border"
                       placeholder="e.g., Conakry"
                       required>
                @error('pod') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
            </div>
            
            {{-- Service Type --}}
            <div class="col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Service Type *
                </label>
                <select wire:model="service_type" 
                        class="form-select w-full px-4 py-3 rounded-lg border"
                        required>
                    <option value="">-- Select Service --</option>
                    @foreach($serviceTypes as $key => $type)
                        <option value="{{ $key }}">{{ is_array($type) ? $type['name'] : $type }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>
    
    {{-- Schedule Selection --}}
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">
            <i class="fas fa-calendar-alt mr-2"></i>Select Sailing
            <span class="text-sm font-normal text-gray-500">(Required for article suggestions)</span>
        </h2>
        
        <select wire:model="selected_schedule_id" 
                class="form-select w-full px-4 py-3 rounded-lg border">
            <option value="">-- Select Schedule --</option>
            @foreach($schedules as $schedule)
                <option value="{{ $schedule->id }}">
                    {{ $schedule->carrier->name ?? 'Unknown' }} - 
                    ETD: {{ $schedule->etd->format('M d, Y') }} - 
                    ETA: {{ $schedule->eta->format('M d, Y') }}
                </option>
            @endforeach
        </select>
    </div>
    
    {{-- Cargo Information --}}
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">
            <i class="fas fa-box mr-2"></i>Cargo Information
        </h2>
        
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Commodity Type *
                </label>
                <select wire:model="commodity_type" 
                        class="form-select w-full px-4 py-3 rounded-lg border"
                        required>
                    <option value="">-- Select Commodity --</option>
                    <option value="cars">Cars</option>
                    <option value="trucks">Trucks</option>
                    <option value="machinery">Machinery</option>
                    <option value="general_goods">General Goods</option>
                    <option value="motorcycles">Motorcycles</option>
                    <option value="breakbulk">Break Bulk</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Cargo Description *
                </label>
                <textarea wire:model.debounce.500ms="cargo_description"
                          rows="3"
                          class="form-input w-full px-4 py-3 rounded-lg border"
                          placeholder="Describe your cargo..."
                          required></textarea>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Special Requirements
                </label>
                <textarea wire:model.debounce.500ms="special_requirements"
                          rows="2"
                          class="form-input w-full px-4 py-3 rounded-lg border"
                          placeholder="Any special handling requirements..."></textarea>
            </div>
        </div>
    </div>
    
    {{-- SMART ARTICLE SELECTOR - Shows when POL+POD+Schedule selected --}}
    @if($showArticles && $quotation)
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-2xl font-bold text-gray-900 mb-6">
                <i class="fas fa-lightbulb mr-2 text-yellow-500"></i>Suggested Services & Pricing
            </h2>
            
            @livewire('smart-article-selector', [
                'quotation' => $quotation,
                'showPricing' => (bool) auth()->user()->pricing_tier_id,
                'isEditable' => true
            ], key('article-selector-' . $quotation->id))
        </div>
    @elseif($pol && $pod && !$selected_schedule_id)
        {{-- Prompt to select schedule --}}
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mb-6">
            <div class="flex items-center">
                <i class="fas fa-info-circle text-yellow-600 text-2xl mr-3"></i>
                <div>
                    <h3 class="text-lg font-semibold text-yellow-900 mb-1">Select a Sailing Schedule</h3>
                    <p class="text-sm text-yellow-800">
                        To see suggested services and pricing, please select a sailing schedule above.
                    </p>
                </div>
            </div>
        </div>
    @endif
    
    {{-- Selected Articles Summary --}}
    @if($quotation && $quotation->articles->count() > 0)
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-check-circle text-green-600 mr-2"></i>
                Selected Articles ({{ $quotation->articles->count() }})
            </h3>
            
            <div class="space-y-3">
                @foreach($quotation->articles as $article)
                    <div class="flex items-center justify-between bg-gray-50 p-3 rounded-lg">
                        <div class="flex-1">
                            <p class="font-medium text-gray-900">{{ $article->description }}</p>
                            <p class="text-sm text-gray-600">
                                Code: {{ $article->article_code }} | 
                                Qty: {{ $article->pivot->quantity }} × 
                                €{{ number_format($article->pivot->unit_price, 2) }} = 
                                <span class="font-semibold">€{{ number_format($article->pivot->quantity * $article->pivot->unit_price, 2) }}</span>
                            </p>
                        </div>
                        <button wire:click="$emit('removeArticle', {{ $article->id }})"
                                class="text-red-600 hover:text-red-800 ml-4">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                @endforeach
            </div>
            
            {{-- Pricing Summary --}}
            @if(auth()->user()->pricing_tier_id)
                <div class="mt-4 pt-4 border-t border-gray-200">
                    <div class="flex justify-between text-sm mb-1">
                        <span class="text-gray-600">Subtotal:</span>
                        <span class="font-semibold">€{{ number_format($quotation->subtotal, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-sm mb-1">
                        <span class="text-gray-600">VAT ({{ $quotation->vat_rate }}%):</span>
                        <span class="font-semibold">€{{ number_format($quotation->vat_amount, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-lg font-bold pt-2 border-t border-gray-300">
                        <span class="text-gray-900">Total:</span>
                        <span class="text-blue-600">€{{ number_format($quotation->total_incl_vat, 2) }}</span>
                    </div>
                </div>
            @endif
        </div>
    @endif
    
    {{-- File Uploads --}}
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">
            <i class="fas fa-paperclip mr-2"></i>Supporting Documents
        </h2>
        
        <input type="file" 
               wire:model="supporting_files" 
               multiple
               accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx"
               class="form-input w-full">
        @error('supporting_files.*') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
    </div>
    
    {{-- Action Buttons --}}
    <div class="flex justify-between items-center">
        <button type="button" 
                wire:click="saveDraft"
                class="px-6 py-3 border border-gray-300 rounded-lg hover:bg-gray-50 transition">
            <i class="fas fa-save mr-2"></i>Save Draft
        </button>
        
        <button type="button" 
                wire:click="submit"
                wire:loading.attr="disabled"
                class="px-8 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition disabled:opacity-50">
            <span wire:loading.remove>
                <i class="fas fa-paper-plane mr-2"></i>Submit for Review
            </span>
            <span wire:loading>
                <i class="fas fa-spinner fa-spin mr-2"></i>Submitting...
            </span>
        </button>
    </div>
    
    {{-- Success/Error Messages --}}
    @if (session()->has('message'))
        <div class="mt-4 bg-green-50 border border-green-200 rounded-lg p-4">
            <p class="text-sm text-green-800">{{ session('message') }}</p>
        </div>
    @endif
</div>
```

---

### Phase 2: Update SmartArticleSelector for Customer Context

#### 2.1 Modify Livewire Component

**File:** `app/Http/Livewire/SmartArticleSelector.php`

**Changes:**
```php
public bool $showPricing = true;
public bool $isEditable = true;
public ?int $pricingTierId = null;

public function mount(QuotationRequest $quotation, bool $showPricing = true, bool $isEditable = true)
{
    $this->quotation = $quotation;
    $this->showPricing = $showPricing;
    $this->isEditable = $isEditable;
    
    // Get pricing tier: User tier → Quotation tier → Default to Tier C
    $this->pricingTierId = auth()->user()?->pricing_tier_id 
        ?? $quotation->pricing_tier_id 
        ?? \App\Models\PricingTier::where('code', 'C')->first()?->id;
    
    $this->suggestedArticles = collect();
    $this->loadSuggestions();
}

public function getTierPrice($article)
{
    // Always return a price - default to Tier C if no tier
    $tier = \App\Models\PricingTier::find($this->pricingTierId);
    
    if (!$tier) {
        // Fallback to Tier C if tier not found
        $tier = \App\Models\PricingTier::where('code', 'C')->first();
    }
    
    if (!$tier) {
        // Ultimate fallback: base price
        return $article->unit_price;
    }
    
    return $tier->calculateSellingPrice($article->unit_price);
}

public function selectArticle($articleId)
{
    if (!$this->isEditable) {
        return;
    }
    
    if (!in_array($articleId, $this->selectedArticles)) {
        $this->selectedArticles[] = $articleId;
        
        $article = \App\Models\RobawsArticleCache::find($articleId);
        $tierPrice = $this->getTierPrice($article);
        
        // Attach with tier price and metadata
        $this->quotation->articles()->syncWithoutDetaching([
            $articleId => [
                'quantity' => 1,
                'unit_price' => $tierPrice ?? $article->unit_price,
                'unit_type' => $article->unit_type,
                'currency' => $article->currency,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
        
        // Recalculate quotation totals
        $this->quotation->calculateTotals();
        $this->quotation->save();
        
        // Refresh quotation
        $this->quotation = $this->quotation->fresh();
        
        // Emit to parent
        $this->emit('articleAdded', $articleId);
    }
}
```

#### 2.2 Update Blade View

**File:** `resources/views/livewire/smart-article-selector.blade.php`

**Key changes:**

1. **Update pricing display (lines 104-107):**
```blade
@php
    // Always show pricing - default to Tier C if no tier assigned
    $displayPrice = $this->getTierPrice($article);
    $tier = \App\Models\PricingTier::find($this->pricingTierId);
    $tierLabel = $tier ? "Tier {$tier->code}" : 'Standard';
@endphp
<p class="text-sm text-gray-600">
    <span class="font-medium">Price:</span> 
    €{{ number_format($displayPrice, 2) }} {{ $article->currency }} / {{ $article->unit_type }}
    <span class="text-xs text-gray-500">({{ $tierLabel }} pricing)</span>
</p>
```

2. **Update field references (lines 132-134):**
```blade
@if($article->pol && $article->pod)
    <p><span class="font-medium">Route:</span> {{ $article->pol }} → {{ $article->pod }}</p>
@endif
```

3. **Update no-match message (lines 172-189):**
```blade
<div class="rounded-lg border border-yellow-200 bg-yellow-50 p-8 text-center">
    <i class="fas fa-exclamation-circle text-yellow-600 text-4xl mb-4"></i>
    <h3 class="text-lg font-semibold text-yellow-900 mb-2">No Matching Articles Found</h3>
    <p class="text-sm text-yellow-800 mb-4">
        We couldn't find pre-configured services matching your exact requirements.
    </p>
    <div class="bg-white border border-yellow-300 rounded-lg p-4">
        <p class="text-sm font-medium text-gray-900 mb-2">
            <i class="fas fa-user-tie text-blue-600 mr-2"></i>
            Don't worry - Our Belgaco team will help!
        </p>
        <p class="text-sm text-gray-700">
            Submit your quotation and our team will review it personally to provide accurate pricing within 24 hours.
        </p>
    </div>
</div>
```

---

### Phase 3: Update Customer Quotation Routes

**File:** `routes/web.php`

**Replace existing customer quotation routes:**
```php
// Customer Portal - Quotations
Route::middleware(['auth:sanctum', 'customer'])->prefix('customer')->name('customer.')->group(function () {
    
    // List quotations
    Route::get('/quotations', [CustomerQuotationController::class, 'index'])
        ->name('quotations.index');
    
    // Create quotation (now Livewire-powered)
    Route::get('/quotations/create', function () {
        return view('customer.quotations.create');
    })->name('quotations.create');
    
    // Show quotation (with inline edit capability)
    Route::get('/quotations/{quotationRequest}', [CustomerQuotationController::class, 'show'])
        ->name('quotations.show');
    
    // Update quotation (inline edit)
    Route::put('/quotations/{quotationRequest}', [CustomerQuotationController::class, 'update'])
        ->name('quotations.update');
    
    // Duplicate quotation
    Route::post('/quotations/{quotationRequest}/duplicate', [CustomerQuotationController::class, 'duplicate'])
        ->name('quotations.duplicate');
    
    // Note: POST store route removed - Livewire handles creation via auto-save draft
});
```

---

### Phase 4: Update Customer Create View

**File:** `resources/views/customer/quotations/create.blade.php`

**Replace entire form section with Livewire component:**
```blade
@extends('customer.layout')

@section('title', 'Request a Quotation')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    
    {{-- Header --}}
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900">Request a Quotation</h1>
        <p class="mt-2 text-gray-600">
            Fill in your shipping details and we'll suggest the best services with pricing.
        </p>
    </div>
    
    {{-- Livewire Component replaces entire form --}}
    @livewire('customer.quotation-creator', [
        'intakeId' => request()->get('intake_id')
    ])
    
</div>
@endsection
```

**This simplifies the create view massively** - all logic moves to Livewire!

---

### Phase 5: Add Draft Cleanup (Housekeeping)

#### 5.1 Schedule Command to Clean Old Drafts

**File:** `app/Console/Commands/CleanDraftQuotations.php` (NEW)

```php
<?php

namespace App\Console\Commands;

use App\Models\QuotationRequest;
use Illuminate\Console\Command;

class CleanDraftQuotations extends Command
{
    protected $signature = 'quotations:clean-drafts {--days=7 : Age in days}';
    protected $description = 'Delete draft quotations older than specified days';

    public function handle()
    {
        $days = (int) $this->option('days');
        
        $deleted = QuotationRequest::where('status', 'draft')
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
        
        $this->info("Deleted {$deleted} draft quotations older than {$days} days");
        
        return Command::SUCCESS;
    }
}
```

**Add to scheduler:** `app/Console/Kernel.php`
```php
protected function schedule(Schedule $schedule)
{
    // Clean old draft quotations weekly
    $schedule->command('quotations:clean-drafts --days=7')->weekly();
}
```

---

### Phase 6: Update Show Page for Post-Approval Viewing

**File:** `resources/views/customer/quotations/show.blade.php`

**Already exists, just add:**

1. **Article display section** (if articles exist)
2. **Duplicate button** (if approved)
3. **Keep existing pricing section** (works as-is)

**Add after existing cargo section:**
```blade
{{-- Selected Articles --}}
@if($quotationRequest->articles->count() > 0)
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-xl font-semibold text-gray-900 mb-4">
            <i class="fas fa-box mr-2"></i>Selected Services
        </h2>
        
        <div class="space-y-3">
            @foreach($quotationRequest->articles as $article)
                <div class="flex items-center justify-between bg-gray-50 p-4 rounded-lg">
                    <div class="flex-1">
                        <p class="font-medium text-gray-900">{{ $article->description }}</p>
                        <p class="text-sm text-gray-600">
                            Code: {{ $article->article_code }}
                        </p>
                        @if(auth()->user()->pricing_tier_id)
                            <p class="text-sm text-gray-700 mt-1">
                                {{ $article->pivot->quantity }} × €{{ number_format($article->pivot->unit_price, 2) }} = 
                                <span class="font-semibold text-blue-600">
                                    €{{ number_format($article->pivot->quantity * $article->pivot->unit_price, 2) }}
                                </span>
                            </p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif

{{-- Duplicate button for approved quotations --}}
@if(in_array($quotationRequest->status, ['quoted', 'accepted', 'approved']))
    <div class="mt-6">
        <form action="{{ route('customer.quotations.duplicate', $quotationRequest) }}" method="POST">
            @csrf
            <button type="submit" 
                    class="w-full inline-flex justify-center items-center px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                <i class="fas fa-copy mr-2"></i>
                Duplicate This Quotation
            </button>
        </form>
        <p class="text-xs text-gray-500 text-center mt-2">
            Create a new quotation based on this one
        </p>
    </div>
@endif
```

---

## Database Changes

### Add 'draft' Status to Quotations

**File:** `database/migrations/2025_10_28_120000_add_draft_status_to_quotation_requests.php` (NEW)

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No schema change needed - status is already a string column
        // Just documenting that 'draft' is now a valid status value
        
        // Valid statuses:
        // - draft: Auto-created, customer filling form
        // - pending: Customer submitted for review
        // - processing: Admin reviewing
        // - quoted: Admin provided pricing
        // - accepted: Customer accepted quote
        // - rejected: Customer declined
        // - expired: Quote validity expired
    }

    public function down(): void
    {
        // No rollback needed
    }
};
```

**Update status documentation in model:**

**File:** `app/Models/QuotationRequest.php`

Add to class docblock:
```php
/**
 * Status values:
 * - draft: Auto-created during form filling (customer portal)
 * - pending: Submitted for review
 * - processing: Under admin review
 * - quoted: Pricing provided by admin
 * - accepted: Customer accepted
 * - rejected: Customer declined
 * - expired: Validity period ended
 */
```

---

## Configuration Updates

**File:** `config/quotation.php`

**Add new section:**
```php
'customer_portal' => [
    'article_selection_enabled' => env('CUSTOMER_ARTICLE_SELECTION', true),
    'pricing_visibility' => env('CUSTOMER_PRICING_VISIBILITY', 'tier_based'), // 'none', 'tier_based', 'full'
    'show_match_percentage' => env('CUSTOMER_SHOW_MATCH_PERCENTAGE', true),
    'allow_custom_articles' => false, // Only suggested articles
    'max_articles_per_quotation' => env('CUSTOMER_MAX_ARTICLES', 20),
    'auto_save_enabled' => env('CUSTOMER_AUTO_SAVE', true),
    'auto_save_debounce_ms' => 500, // Debounce time for auto-save
    'draft_cleanup_days' => env('DRAFT_CLEANUP_DAYS', 7),
],

'quotation_statuses' => [
    'draft' => [
        'name' => 'Draft',
        'color' => 'gray',
        'description' => 'Customer is filling form',
        'editable_by_customer' => true,
        'visible_in_admin' => false, // Don't clutter admin with drafts
    ],
    'pending' => [
        'name' => 'Pending',
        'color' => 'yellow',
        'description' => 'Submitted for review',
        'editable_by_customer' => true,
        'visible_in_admin' => true,
    ],
    'processing' => [
        'name' => 'Processing',
        'color' => 'blue',
        'description' => 'Under team review',
        'editable_by_customer' => true,
        'visible_in_admin' => true,
    ],
    'quoted' => [
        'name' => 'Quoted',
        'color' => 'green',
        'description' => 'Pricing provided',
        'editable_by_customer' => false,
        'visible_in_admin' => true,
    ],
    'accepted' => [
        'name' => 'Accepted',
        'color' => 'purple',
        'description' => 'Customer accepted',
        'editable_by_customer' => false,
        'visible_in_admin' => true,
    ],
],
```

---

## Controller Updates

### Remove Old Store Method, Add Duplicate

**File:** `app/Http/Controllers/CustomerQuotationController.php`

**Remove:** The entire `store()` method (Livewire handles creation now)

**Add duplicate method:**
```php
/**
 * Duplicate an approved quotation
 */
public function duplicate(QuotationRequest $quotationRequest)
{
    $user = auth()->user();
    
    // Verify ownership
    if ($quotationRequest->contact_email !== $user->email && 
        $quotationRequest->client_email !== $user->email) {
        abort(403);
    }
    
    DB::transaction(function () use ($quotationRequest, &$newQuotation) {
        // Create new draft quotation
        $newQuotation = $quotationRequest->replicate([
            'request_number',
            'status',
            'quoted_at',
            'expires_at',
            'robaws_offer_id',
            'robaws_offer_number',
            'robaws_synced_at',
        ]);
        
        $newQuotation->status = 'draft';
        $newQuotation->save();
        
        // Copy articles with same pricing
        foreach ($quotationRequest->articles as $article) {
            $newQuotation->articles()->attach($article->id, [
                'quantity' => $article->pivot->quantity,
                'unit_price' => $article->pivot->unit_price,
                'unit_type' => $article->pivot->unit_type,
                'currency' => $article->pivot->currency,
            ]);
        }
        
        // Copy commodity items
        foreach ($quotationRequest->commodityItems as $item) {
            $newItem = $item->replicate();
            $newItem->quotation_request_id = $newQuotation->id;
            $newItem->save();
        }
    });
    
    return redirect()
        ->route('customer.quotations.create') // Redirect to create, Livewire will load draft
        ->with('success', 'Quotation duplicated! You can now edit and submit.');
}
```

---

## Files to be Created/Modified

### New Files (3)
1. `app/Http/Livewire/Customer/QuotationCreator.php` - Main creation component
2. `resources/views/livewire/customer/quotation-creator.blade.php` - Component view
3. `app/Console/Commands/CleanDraftQuotations.php` - Cleanup command

### Modified Files (7)
1. `app/Http/Controllers/CustomerQuotationController.php` - Remove store, add duplicate, modify show
2. `app/Http/Livewire/SmartArticleSelector.php` - Add tier pricing, editable flag
3. `resources/views/livewire/smart-article-selector.blade.php` - Update UI, fix field names (pol/pod)
4. `resources/views/customer/quotations/create.blade.php` - Replace with Livewire component
5. `resources/views/customer/quotations/show.blade.php` - Add articles display, duplicate button
6. `config/quotation.php` - Add customer_portal config, status definitions
7. `routes/web.php` - Update routes (remove store POST, add duplicate)

### Total Changes
- **10 files** total (3 new + 7 modified)
- **~800 lines** of new code
- **~300 lines** removed (old HTML form)
- **Net: ~500 lines**

---

## Key Technical Decisions

### 1. Auto-Save Strategy

**Trigger:** `wire:model.debounce.500ms`
- Waits 500ms after user stops typing
- Then auto-saves to draft quotation
- Prevents excessive database writes

**Fields that trigger article reload:**
- `pol` → Check if conditions met
- `pod` → Check if conditions met
- `selected_schedule_id` → Triggers article load if POL+POD exist
- `commodity_type` → Reloads articles with new filter

### 2. Draft vs. Pending Status

**Draft:**
- Created automatically when customer lands on create page
- Customer is still filling form
- NOT visible in Filament admin (reduces clutter)
- Auto-deleted after 7 days if not submitted

**Pending:**
- Customer clicked "Submit for Review"
- Visible to Belgaco team in Filament
- Customer can still edit until admin reviews

### 3. Pricing Tier Handling ✅ **UPDATED**

**Customer WITH pricing_tier_id:**
- Sees tier-adjusted prices
- Example: €787 base → Tier B (15%) → €905.05

**Customer WITHOUT pricing_tier_id:**
- ✅ **Defaults to Tier C** (most expensive)
- Shows Tier C pricing automatically
- Example: €787 base → Tier C (25%) → €983.75
- This protects margins while still showing pricing

**Pricing Logic:**
```php
$pricingTier = $user->pricing_tier_id 
    ? PricingTier::find($user->pricing_tier_id)
    : PricingTier::where('code', 'C')->first(); // Default to Tier C

$displayPrice = $pricingTier->calculateSellingPrice($article->unit_price);
```

**Business Rule:** Never show "Contact us for pricing" - always show at least Tier C pricing to maintain transparency while protecting margins.

### 4. Article Selection Flow

```
Customer selects article
    ↓
SmartArticleSelector.selectArticle()
    ↓
Attach to quotation with tier price
    ↓
calculateTotals() on quotation
    ↓
Emit 'articleAdded' event
    ↓
QuotationCreator handles event
    ↓
Refreshes quotation state
    ↓
Pricing summary updates
```

---

## Testing Strategy

### Unit Tests
- [ ] Draft quotation creation
- [ ] Auto-save functionality
- [ ] Tier price calculation
- [ ] Article attachment with correct pricing
- [ ] Total calculation accuracy
- [ ] Draft cleanup command

### Integration Tests
- [ ] Complete creation flow (POL → POD → Schedule → Articles → Submit)
- [ ] Article suggestions update when fields change
- [ ] Pricing visibility based on tier
- [ ] Duplication copies all data correctly
- [ ] Edit locking after approval

### E2E Tests
- [ ] Customer with tier sees pricing
- [ ] Customer without tier sees "Contact us"
- [ ] Articles filter by commodity correctly
- [ ] Mobile responsive on all devices
- [ ] No articles match → Shows message
- [ ] Draft auto-saves work
- [ ] Submit changes status to pending

---

## Rollout Strategy

### Phase 1: Development (Day 1-2)
- Create Livewire component
- Update SmartArticleSelector
- Update routes and views
- Basic testing

### Phase 2: Internal Testing (Day 3)
- Test all flows
- Mobile testing
- Bug fixes
- Performance optimization

### Phase 3: Staging Deployment (Day 3-4)
- Deploy to staging
- Team walkthrough
- Final adjustments

### Phase 4: Production Rollout (Day 5)
- Deploy to production
- Monitor error logs
- Watch for support tickets
- Gradual rollout if needed

---

## Risk Mitigation

### Risk 1: Draft Quotation Clutter
**Risk:** Database fills with abandoned drafts  
**Mitigation:** Auto-cleanup command runs weekly, deletes drafts older than 7 days

### Risk 2: Auto-Save Performance
**Risk:** Too many database writes  
**Mitigation:** Debounce 500ms, only save changed fields

### Risk 3: Livewire Learning Curve
**Risk:** Team unfamiliar with Livewire debugging  
**Mitigation:** Comprehensive logging, clear error messages

### Risk 4: Customer Confusion
**Risk:** Auto-save might confuse customers  
**Mitigation:** Clear "Auto-saved" indicator, "Draft ID" displayed

---

## Success Metrics

### Quantitative KPIs
- **Completion rate** - % of quotations submitted with articles (Target: 70%+)
- **Average time to submit** - Time from start to submit (Target: < 10 minutes)
- **Articles per quotation** - Average articles selected (Target: 2-3)
- **Draft abandonment** - % of drafts never submitted (Baseline metric)
- **Edit rate post-submission** - % of customers who edit after submitting (Target: < 20%)

### Qualitative Feedback
- Customer satisfaction with transparency
- Ease of use vs. old flow
- Pricing clarity
- Time savings for customers

---

## Timeline Estimate - **REVISED for Livewire Approach**

### Day 1: Livewire Component (8 hours)
- Create QuotationCreator component
- Implement auto-save draft logic
- Add field bindings with debounce
- Test basic form functionality

### Day 2: Article Integration (6-8 hours)
- Integrate SmartArticleSelector into creator
- Add conditional display logic (POL+POD+Schedule)
- Implement tier pricing display
- Add article selection handlers
- Update totals calculation

### Day 3: Show/Edit/Duplicate (4-6 hours)
- Update show page to display articles
- Add duplicate functionality
- Test edit locking
- Mobile responsive testing

### Day 4: Testing & Refinement (6-8 hours)
- Full E2E testing
- Bug fixes
- Performance optimization
- Documentation

**Total:** 3-4 days for complete implementation

---

## Final Confirmation ✅ **ALL CONFIRMED**

1. ✅ **Auto-save draft approach approved** - Creates quotation immediately on page load
2. ✅ **Draft status approved** - Hidden from admin until submitted
3. ✅ **Admin notifications** - NO notification when articles added, only on final submit
4. ✅ **Draft cleanup** - YES, weekly cleanup of 7+ day old drafts
5. ✅ **Draft persistence** - YES, draft remains and customer can resume later (if saved)
6. ✅ **Save options** - BOTH "Save Draft" button AND auto-save (dual approach for user confidence)

---

## Comparison: Old Plan vs. New Plan

### Old Plan (Two-Step)
- ❌ Customer submits blind (no articles)
- ❌ Must edit after seeing articles
- ❌ Extra step/page required
- ✅ Simpler implementation

### New Plan (Auto-Save Draft) ✅ **RECOMMENDED**
- ✅ Customer sees articles DURING creation
- ✅ Submits complete quotation with articles
- ✅ Better UX - seamless flow
- ⚠️ More complex (Livewire required)
- ⚠️ Draft cleanup needed

**Decision:** Proceed with auto-save draft for superior UX

---

**✅ FULLY APPROVED - Ready for implementation!**

## Implementation Features Summary

**Auto-Save:**
- ✅ Auto-saves every 500ms after user stops typing
- ✅ Manual "Save Draft" button for user confidence
- ✅ Visual indicator shows save status

**Draft Persistence:**
- ✅ Draft saved to database (can resume later)
- ✅ Auto-cleanup after 7 days (configurable)
- ✅ Draft ID displayed to user

**Notifications:**
- ✅ Admin notified only on "Submit for Review" (not on article add)
- ✅ Reduces notification noise
- ✅ Customer sees confirmation on submit

**Next Step:** Begin implementation!

## Implementation Checklist

- [ ] Create QuotationCreator Livewire component
- [ ] Update SmartArticleSelector for tier pricing
- [ ] Convert create.blade.php to use Livewire
- [ ] Add draft cleanup command
- [ ] Update show.blade.php with articles display
- [ ] Add duplicate functionality
- [ ] Update routes
- [ ] Update config
- [ ] Test all flows
- [ ] Deploy to production
