<?php

namespace App\Livewire\Customer;

use Livewire\Component;
use Livewire\WithFileUploads;
use App\Models\QuotationRequest;
use App\Models\Port;
use App\Models\ShippingSchedule;
use App\Models\ShippingCarrier;
use App\Models\PricingTier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Ports\PortResolutionService;

class QuotationCreator extends Component
{
    use WithFileUploads;
    
    // Quotation ID (draft created on mount)
    public ?int $quotationId = null;
    public ?QuotationRequest $quotation = null;
    
    // Form fields
    public $pol = '';
    public ?int $pol_port_id = null;
    public $pod = '';
    public ?int $pod_port_id = null;
    public $por = '';
    public $fdest = '';
    public $in_transit_to = '';
    public $simple_service_type = 'SEA_RORO';
    public $service_type = 'RORO_EXPORT';
    public $cargo_description = '';
    public $special_requirements = '';
    public $selected_schedule_id = null;
    public $customer_reference = '';
    
    // File uploads
    public $supporting_files = [];
    
    // State
    public bool $showArticles = false;
    public bool $loading = false;
    public bool $submitting = false;
    
    // Track previous service category to detect sea ↔ air switches
    protected ?string $previousServiceCategory = null;
    
    // Listen for article selection events and port updates
    protected $listeners = [
        'articleAdded' => 'handleArticleAdded',
        'articleRemoved' => 'handleArticleRemoved',
        'port-updated' => 'handlePortUpdated',
        'commodity-item-saved' => 'handleCommodityItemSaved',
        'commodity-items-cleared' => 'handleCommodityItemsCleared',
    ];
    
    // Handle port-updated event from JavaScript (fallback method)
    public function handlePortUpdated($data)
    {
        if (isset($data['field']) && isset($data['value'])) {
            $this->setPort($data['field'], $data['value']);
        }
    }
    
    /**
     * Handle commodity item saved event from nested CommodityItemsRepeater component
     * Refresh quotation and update showArticles flag
     */
    public function handleCommodityItemSaved($data)
    {
        // Refresh quotation to load latest commodityItems
        if ($this->quotation) {
            $this->quotation = $this->quotation->fresh(['commodityItems', 'selectedSchedule.carrier', 'articles']);
        }
        
        // Update showArticles flag (commodity item might now have commodity_type set)
        $this->updateShowArticles();
        
        // Always dispatch quotationUpdated if articles are showing
        // This ensures SmartArticleSelector reloads when commodity items change
        if ($this->showArticles) {
            $this->dispatch('quotationUpdated');
        }
        
        // Log for debugging
        Log::info('QuotationCreator::handleCommodityItemSaved() called', [
            'quotation_id' => $this->quotationId,
            'commodity_items_count' => $this->quotation?->commodityItems?->count() ?? 0,
            'show_articles' => $this->showArticles,
            'event_dispatched' => $this->showArticles
        ]);
    }

    /**
     * Handle all commodity items cleared (UI should immediately clear pricing/services).
     */
    public function handleCommodityItemsCleared($data): void
    {
        if ($this->quotation) {
            $this->quotation = $this->quotation->fresh(['commodityItems', 'selectedSchedule.carrier', 'articles']);

            // Clear displayed services and totals immediately in UI
            $this->quotation->setRelation('articles', collect());
            $this->quotation->subtotal = 0;
            $this->quotation->discount_amount = 0;
            $this->quotation->total_excl_vat = 0;
            $this->quotation->vat_amount = 0;
            $this->quotation->total_incl_vat = 0;
        }

        $this->showArticles = false;
        $this->resetErrorBag('commodity_items');

        $this->dispatch('quotationUpdated');

        Log::info('QuotationCreator::handleCommodityItemsCleared() called', [
            'quotation_id' => $this->quotationId,
        ]);
    }
    
    // Public method to set POL/POD from JavaScript (used with wire:ignore)
    public function setPort($field, $value)
    {
        if (!in_array($field, ['pol', 'pod'])) {
            return;
        }
        
        $this->$field = $value;
        
        // Manually trigger updated() logic since wire:ignore prevents automatic updates
        $this->updated($field);
    }
    
    public function mount($intakeId = null, $quotationId = null)
    {
        // If editing existing quotation, load it
        if ($quotationId) {
            $this->loadQuotationForEditing($quotationId);
            return;
        }

        if (empty($this->simple_service_type)) {
            $this->simple_service_type = 'SEA_RORO';
        }

        $this->service_type = config("quotation.simple_service_types.{$this->simple_service_type}.default_service_type", 'RORO_EXPORT');

        // Initialize service category tracking
        $this->previousServiceCategory = $this->getServiceCategory($this->simple_service_type);

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
                'status' => 'pending',
                'source' => 'customer',
                'requester_type' => 'customer',
                'contact_email' => $user->email,
                'contact_name' => $user->name,
                'contact_phone' => $user->phone ?? null,
                'client_email' => $user->email,
                'client_name' => $user->name,
                'client_tel' => $user->phone ?? null,
                'pricing_tier_id' => $user->pricing_tier_id ?? $this->getDefaultPricingTierId(),
                'customer_role' => $user->customer_role ?? 'RORO',
                'vat_rate' => 21.00,
                'pricing_currency' => 'EUR',
                // Required fields - will be updated when customer fills form
                'service_type' => $this->service_type, // Default, can be updated by customer
                'simple_service_type' => $this->simple_service_type, // Default selection
                'trade_direction' => $this->getDirectionFromServiceType($this->service_type),
                'cargo_description' => 'Draft - being filled by customer', // Default, will be updated
                'pol' => '', // Will be filled by customer
                'pod' => '', // Will be filled by customer
                'routing' => [ // Will be updated as customer fills form
                    'por' => '',
                    'pol' => '',
                    'pod' => '',
                    'fdest' => '',
                ],
                'in_transit_to' => '',
                'cargo_details' => [], // Will be populated with commodity items
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
        return PricingTier::where('code', 'C')->first()?->id;
    }
    
    protected function prefillFromIntake($intakeId)
    {
        // TODO: Implement intake prefill logic if needed
        // This would load data from an intake form
    }
    
    /**
     * Load an existing quotation for editing
     */
    protected function loadQuotationForEditing($quotationId)
    {
        $user = auth()->user();
        
        // Load quotation and verify ownership
        $quotation = QuotationRequest::where('id', $quotationId)
            ->where(function($query) use ($user) {
                $query->where('contact_email', $user->email)
                      ->orWhere('client_email', $user->email);
            })
            ->with(['commodityItems', 'selectedSchedule.carrier', 'articles'])
            ->firstOrFail();
        
        // Verify editing is allowed
        if (!in_array($quotation->status, ['draft', 'pending', 'processing'])) {
            abort(403, 'This quotation cannot be edited');
        }
        
        $this->quotation = $quotation;
        $this->quotationId = $quotation->id;
        
        // Load all form fields from quotation
        // Check individual columns first, then fall back to routing JSON (for older quotations)
        $routing = $quotation->routing ?? [];
        $this->pol = $quotation->pol ?? $routing['pol'] ?? '';
        $this->pod = $quotation->pod ?? $routing['pod'] ?? '';
        $this->pol_port_id = $quotation->pol_port_id;
        $this->pod_port_id = $quotation->pod_port_id;
        $this->por = $quotation->por ?? $routing['por'] ?? '';
        $this->fdest = $quotation->fdest ?? $routing['fdest'] ?? '';
        $this->in_transit_to = $quotation->in_transit_to ?? '';
        $this->service_type = $quotation->service_type ?? 'RORO_EXPORT';
        $this->simple_service_type = $quotation->simple_service_type ?? 'SEA_RORO';
        $this->cargo_description = $quotation->cargo_description ?? '';
        $this->special_requirements = $quotation->special_requirements ?? '';
        $this->selected_schedule_id = $quotation->selected_schedule_id;
        $this->customer_reference = $quotation->customer_reference ?? '';
        
        // Initialize service category tracking
        $this->previousServiceCategory = $this->getServiceCategory($this->simple_service_type);
        
        // Update showArticles flag based on current state
        $this->updateShowArticles();
        
        Log::info('Quotation loaded for editing', [
            'quotation_id' => $this->quotationId,
            'user_email' => $user->email,
            'status' => $quotation->status,
            'pol' => $this->pol,
            'pod' => $this->pod,
            'por' => $this->por,
            'fdest' => $this->fdest,
            'pol_from_column' => $quotation->pol,
            'pod_from_column' => $quotation->pod,
            'routing_json' => $quotation->routing,
        ]);
    }
    
    // Auto-save when fields change
    public function updated($propertyName)
    {
        // Skip auto-save for file uploads (handled separately)
        if ($propertyName === 'supporting_files') {
            return;
        }
        
        // Handle schedule change explicitly
        if ($propertyName === 'selected_schedule_id') {
            // Dispatch event to CommodityItemsRepeater to recalculate LM
            $this->dispatch('scheduleChanged');
        }
        
        // Also dispatch when POD changes (schedule might change when POD changes)
        if ($propertyName === 'pod') {
            // Dispatch event to CommodityItemsRepeater to recalculate LM when POD changes
            // This ensures LM is recalculated if schedule changes due to POD change
            $this->dispatch('scheduleChanged');
        }
        
        // Map simple service type to actual service type
        if ($propertyName === 'simple_service_type') {
            $previousServiceType = $this->service_type;
            $currentCategory = $this->getServiceCategory($this->simple_service_type);
            $categoryChanged = $this->previousServiceCategory !== null && $this->previousServiceCategory !== $currentCategory;
            
            $this->service_type = config(
                "quotation.simple_service_types.{$this->simple_service_type}.default_service_type",
                $this->service_type
            );

            // Update previous category for next change
            $this->previousServiceCategory = $currentCategory;

            // Clear route fields if category changed (sea ↔ air) or service type changed
            if ($categoryChanged || $previousServiceType !== $this->service_type) {
                $this->handleServiceTypeChanged();
            }
        }
        
        // Cast selected_schedule_id to int if it's not null/empty (handle string "0" or empty string)
        if ($propertyName === 'selected_schedule_id') {
            $this->selected_schedule_id = $this->selected_schedule_id ? (int) $this->selected_schedule_id : null;
        }
        
        // Resolve port IDs when POL/POD changes
        if (in_array($propertyName, ['pol', 'pod'], true)) {
            $this->resolvePortIds();
        }

        // Save to draft quotation
        if ($this->quotation) {
            $updateData = [
                'pol' => $this->pol,
                'pol_port_id' => $this->pol_port_id,
                'pod' => $this->pod,
                'pod_port_id' => $this->pod_port_id,
                'por' => $this->por,
                'fdest' => $this->fdest,
                'in_transit_to' => $this->in_transit_to,
                'routing' => [
                    'por' => $this->por,
                    'pol' => $this->pol,
                    'pod' => $this->pod,
                    'fdest' => $this->fdest,
                ],
                'service_type' => $this->service_type,
                'simple_service_type' => $this->simple_service_type,
                'trade_direction' => $this->getDirectionFromServiceType($this->service_type),
                'cargo_description' => $this->cargo_description,
                'special_requirements' => $this->special_requirements,
                'selected_schedule_id' => $this->selected_schedule_id,
                'customer_reference' => $this->customer_reference,
            ];
            
            $this->quotation->update($updateData);
            
            // Refresh quotation to ensure relationships are loaded
            $this->quotation = $this->quotation->fresh(['selectedSchedule.carrier']);
            
            // Update showArticles flag using shared method
            $this->updateShowArticles();
            
            // INFO-level logging for production debugging
            Log::info('QuotationCreator state updated', [
                'quotation_id' => $this->quotationId,
                'field' => $propertyName,
                'pol' => $this->pol,
                'pod' => $this->pod,
                'pol_port_id' => $this->pol_port_id,
                'pod_port_id' => $this->pod_port_id,
                'selected_schedule_id' => $this->selected_schedule_id,
                'pol_filled' => !empty(trim($this->pol)),
                'pod_filled' => !empty(trim($this->pod)),
                'schedule_selected' => $this->selected_schedule_id !== null && $this->selected_schedule_id > 0,
                'show_articles' => $this->showArticles,
            ]);
        }
    }

    protected function resolvePortIds(): void
    {
        $mode = $this->isAirService() ? 'AIR' : 'SEA';
        $resolver = app(PortResolutionService::class);

        $this->pol_port_id = $this->resolvePortIdFromInput($resolver, $this->pol, $mode);
        $this->pod_port_id = $this->resolvePortIdFromInput($resolver, $this->pod, $mode);
    }

    protected function resolvePortIdFromInput(PortResolutionService $resolver, ?string $value, string $mode): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            $port = $resolver->resolveOne($value, $mode);
            return $port?->id;
        } catch (\Exception $e) {
            Log::warning('QuotationCreator: failed to resolve port ID', [
                'value' => $value,
                'mode' => $mode,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
    
    /**
     * Explicit handler for schedule selection to ensure immediate update
     */
    public function updatedSelectedScheduleId($value)
    {
        // Ensure value is cast to int
        $this->selected_schedule_id = $value ? (int) $value : null;
        
        // Immediately update showArticles (don't wait for general updated())
        $this->updateShowArticles();
        
        // Dispatch event globally - Livewire will broadcast to all child components
        $this->dispatch('scheduleChanged');
        
        // Recalculation is handled by QuotationRequest::saved when selected_schedule_id changes.
        
        // Call parent updated() for database save and logging
        $this->updated('selected_schedule_id');
    }
    
    /**
     * Shared method to update showArticles flag based on current state
     */
    protected function updateShowArticles()
    {
        $polFilled = !empty(trim($this->pol));
        $podFilled = !empty(trim($this->pod));
        $scheduleSelected = $this->selected_schedule_id !== null && $this->selected_schedule_id > 0;
        
        // Check if quotation has commodity items with commodity_type set
        // Items are now auto-saved to database, so we only need to check the database
        $commoditySelected = false;
        if ($this->quotation) {
            $quotation = $this->quotation->fresh(['commodityItems']);
            if ($quotation->commodityItems && $quotation->commodityItems->count() > 0) {
                // Check if at least one item has a commodity_type set
                foreach ($quotation->commodityItems as $item) {
                    if (!empty($item->commodity_type)) {
                        $commoditySelected = true;
                        break;
                    }
                }
            }
        }
        
        // Only show articles when ALL required fields are filled (POL, POD, Schedule, Commodity)
        // In detailed mode, we're more lenient - if POL/POD/Schedule are set, we allow articles to show
        // This helps users see articles while they're filling out commodity items
        $this->showArticles = $polFilled && $podFilled && $scheduleSelected && $commoditySelected;
        
        // Emit event to SmartArticleSelector to reload
        if ($this->showArticles) {
            $this->dispatch('quotationUpdated');
        }
    }
    
    /**
     * Get effective commodity type for display (from commodity items)
     */
    public function getEffectiveCommodityType(): string
    {
        if ($this->quotation) {
            $quotation = $this->quotation->fresh(['commodityItems']);
            if ($quotation->commodityItems && $quotation->commodityItems->count() > 0) {
                // Get first item with commodity_type set
                foreach ($quotation->commodityItems as $item) {
                    if (!empty($item->commodity_type)) {
                        $type = $item->commodity_type;
                        // If it's a vehicle, include the category
                        if ($type === 'vehicles' && !empty($item->category)) {
                            return $type . ' (' . $item->category . ')';
                        }
                        return $type;
                    }
                }
            }
        }
        
        return '';
    }
    
    public function handleArticleAdded($articleId)
    {
        // Recalculate totals
        $this->quotation->fresh()->calculateTotals();
        $this->quotation = $this->quotation->fresh();
        
        Log::info('Article added to draft quotation', [
            'quotation_id' => $this->quotationId,
            'article_id' => $articleId,
            'total' => $this->quotation->total_incl_vat,
        ]);
    }
    
    public function handleArticleRemoved($articleId)
    {
        // Recalculate totals
        $this->quotation->fresh()->calculateTotals();
        $this->quotation = $this->quotation->fresh();
        
        Log::info('Article removed from draft quotation', [
            'quotation_id' => $this->quotationId,
            'article_id' => $articleId,
        ]);
    }
    
    /**
     * Check if an article is a mandatory child (cannot be removed)
     */
    public function isMandatoryChild($articleId): bool
    {
        if (!$this->quotation) {
            return false;
        }
        
        $quotationArticle = \App\Models\QuotationRequestArticle::where('quotation_request_id', $this->quotation->id)
            ->where('article_cache_id', $articleId)
            ->first();
        
        return $quotationArticle ? $quotationArticle->isMandatoryChild() : false;
    }
    
    /**
     * Get optional composite items available for selection
     * Returns optional children of all selected parent articles
     */
    public function getOptionalItems(): \Illuminate\Support\Collection
    {
        if (!$this->quotation) {
            return collect();
        }
        
        // Ensure quotation is fresh with relationships
        $this->quotation = $this->quotation->fresh(['pricingTier']);
        
        $optionalItems = collect();
        
        // Get all parent articles in the quotation
        $parentQuotationArticles = \App\Models\QuotationRequestArticle::where('quotation_request_id', $this->quotation->id)
            ->whereIn('item_type', ['parent', 'standalone'])
            ->with('articleCache')
            ->get();
        
        foreach ($parentQuotationArticles as $parentQuotationArticle) {
            $parent = $parentQuotationArticle->articleCache;
            
            if (!$parent || !$parent->is_parent_item) {
                continue;
            }
            
            // Get optional children of this parent
            $optionalChildren = $parent->optionalChildren()->get();
            
            foreach ($optionalChildren as $child) {
                // Check if already added to quotation
                $alreadyAdded = \App\Models\QuotationRequestArticle::where('quotation_request_id', $this->quotation->id)
                    ->where('article_cache_id', $child->id)
                    ->exists();
                
                if (!$alreadyAdded) {
                    $optionalItems->push([
                        'article' => $child,
                        'parent' => $parent,
                        'parent_quotation_article_id' => $parentQuotationArticle->id,
                        'pivot' => $child->pivot,
                        'already_added' => false,
                    ]);
                }
            }
        }
        
        // Remove duplicates by article ID
        return $optionalItems->unique(function ($item) {
            return $item['article']->id;
        })->values();
    }
    
    /**
     * Add an optional composite item to the quotation
     */
    public function addOptionalItem($articleId, $parentQuotationArticleId = null)
    {
        if (!$this->quotation) {
            return;
        }
        
        $article = \App\Models\RobawsArticleCache::find($articleId);
        if (!$article) {
            return;
        }
        
        // Find the parent quotation article if not provided
        if (!$parentQuotationArticleId) {
            // Find parent that has this as optional child
            $parentQuotationArticles = \App\Models\QuotationRequestArticle::where('quotation_request_id', $this->quotation->id)
                ->whereIn('item_type', ['parent', 'standalone'])
                ->with('articleCache')
                ->get();
            
            foreach ($parentQuotationArticles as $parentQuotationArticle) {
                $parent = $parentQuotationArticle->articleCache;
                
                if (!$parent || !$parent->is_parent_item) {
                    continue;
                }
                
                $optionalChild = $parent->optionalChildren()
                    ->where('robaws_articles_cache.id', $articleId)
                    ->first();
                
                if ($optionalChild) {
                    $parentQuotationArticleId = $parentQuotationArticle->id;
                    break;
                }
            }
        }
        
        if (!$parentQuotationArticleId) {
            return; // Could not find parent
        }
        
        $parentQuotationArticle = \App\Models\QuotationRequestArticle::find($parentQuotationArticleId);
        if (!$parentQuotationArticle) {
            return;
        }
        
        $parent = $parentQuotationArticle->articleCache;
        if (!$parent) {
            return;
        }
        
        // Get pivot data for default values
        $childRelation = $parent->optionalChildren()
            ->where('robaws_articles_cache.id', $articleId)
            ->first();
        
        // Calculate selling price BEFORE creating the model (required for NOT NULL constraint)
        $role = $this->quotation->customer_role;
        $sellingPrice = null;
        try {
            if ($this->quotation->pricing_tier_id && $this->quotation->pricingTier) {
                $sellingPrice = $article->getPriceForTier($this->quotation->pricingTier);
            } else {
                $sellingPrice = $article->getPriceForRole($role ?: 'default');
            }
        } catch (\Exception $e) {
            $sellingPrice = $article->getPriceForRole($role ?: 'default');
        }
        
        // Ensure we have a valid selling price
        if ($sellingPrice === null || $sellingPrice === 0) {
            $sellingPrice = $article->unit_price ?? 0;
        }
        
        $quantity = $childRelation && $childRelation->pivot ? ($childRelation->pivot->default_quantity ?? 1) : 1;
        $unitPrice = $childRelation && $childRelation->pivot ? ($childRelation->pivot->default_cost_price ?? $article->unit_price) : $article->unit_price;
        
        // Create the quotation request article with all required fields including selling_price
        $quotationRequestArticle = \App\Models\QuotationRequestArticle::create([
            'quotation_request_id' => $this->quotation->id,
            'article_cache_id' => $article->id,
            'parent_article_id' => $parent->id,
            'item_type' => 'child',
            'quantity' => $quantity,
            'unit_type' => $childRelation && $childRelation->pivot ? ($childRelation->pivot->unit_type ?? $article->unit_type ?? 'unit') : ($article->unit_type ?? 'unit'),
            'unit_price' => $unitPrice,
            'selling_price' => $sellingPrice,
            'currency' => $article->currency,
        ]);
        
        // Refresh quotation
        $this->quotation = $this->quotation->fresh(['articles']);
        $this->quotation->calculateTotals();
        $this->quotation->save();
        
        $this->dispatch('articleAdded', articleId: $articleId);
    }
    
    public function saveDraft()
    {
        // Already auto-saving, just show confirmation message
        session()->flash('message', 'Draft saved successfully! You can resume later.');
        
        Log::info('Customer manually saved draft', [
            'quotation_id' => $this->quotationId,
        ]);
    }
    
    public function submit()
    {
        $this->submitting = true;
        
        // Base validation
        $rules = [
            'pol' => 'required|string|max:255',
            'pod' => 'required|string|max:255',
            'simple_service_type' => 'required|string',
        ];
        
        // Always require commodity items
        $this->quotation = $this->quotation->fresh(['commodityItems']);
        
        if (!$this->quotation->commodityItems || $this->quotation->commodityItems->count() === 0) {
            $this->addError('commodity_items', 'At least one commodity item is required.');
            $this->submitting = false;
            return;
        }
        
        // Validate that all commodity items have commodity_type set
        foreach ($this->quotation->commodityItems as $item) {
            if (empty($item->commodity_type)) {
                $this->addError('commodity_items', 'All commodity items must have a commodity type selected.');
                $this->submitting = false;
                return;
            }
        }
        
        // Run validation
        $this->validate($rules);
        
        // Ensure all commodity items are saved
        // Refresh to get latest items
        $this->quotation = $this->quotation->fresh(['commodityItems']);
        
        // Change status from 'draft' to 'pending'
        $this->quotation->update([
            'status' => 'pending',
        ]);
        
        // TODO: Send notification to admin team
        // Notification::route('mail', config('quotation.admin_email'))
        //     ->notify(new QuotationSubmittedNotification($this->quotation));
        
        Log::info('Quotation submitted for review', [
            'quotation_id' => $this->quotationId,
            'articles_count' => $this->quotation->articles->count(),
            'commodity_items_count' => $this->quotation->commodityItems?->count() ?? 0,
        ]);
        
        // Redirect to show page
        return redirect()
            ->route('customer.quotations.show', $this->quotation)
            ->with('success', 'Quotation submitted for review! Our team will respond within 24 hours.');
    }
    
    /**
     * Extract port name and code from various formats:
     * - "Dakar (DKR), Senegal" → name: "Dakar", code: "DKR"
     * - "Antwerp (ANR), Belgium" → name: "Antwerp", code: "ANR"
     * - "Dakar, Senegal" → name: "Dakar", code: ""
     * - "Dakar" → name: "Dakar", code: ""
     */
    protected function extractPortInfo(string $portInput): array
    {
        $portInput = trim($portInput);
        $name = '';
        $code = '';
        
        // Extract code from parentheses if present: "City (CODE), Country"
        if (preg_match('/^(.+?)\s*\(([^)]+)\)/', $portInput, $matches)) {
            $name = trim($matches[1]); // "Dakar"
            $code = trim($matches[2]); // "DKR"
        } else {
            // No parentheses, extract name (everything before comma)
            $parts = explode(',', $portInput);
            $name = trim($parts[0]);
        }
        
        return [
            'name' => $name,
            'code' => $code,
        ];
    }
    
    /**
     * Derive trade direction from service type
     */
    protected function getDirectionFromServiceType(string $serviceType): string
    {
        if (str_contains($serviceType, '_EXPORT')) {
            return 'export';
        }
        if (str_contains($serviceType, '_IMPORT')) {
            return 'import';
        }
        if ($serviceType === 'CROSSTRADE') {
            return 'cross_trade';
        }
        // For ROAD_TRANSPORT, CUSTOMS, PORT_FORWARDING, OTHER
        return 'both';
    }
    
    public function render()
    {
        $isAirService = $this->isAirService();

        $polPorts = $isAirService
            ? Port::forAirports()->orderBy('name')->get()
            : Port::europeanOrigins()->orderBy('name')->get();

        // Extract port name and code from formats like:
        // "Dakar (DKR), Senegal" or "Antwerp (ANR), Belgium"
        // "Dakar, Senegal" or just "Dakar"
        $polName = '';
        $polCode = '';
        if (!$isAirService && $this->pol) {
            $polParts = $this->extractPortInfo($this->pol);
            $polName = $polParts['name'];
            $polCode = $polParts['code'];
        }
        
        $podName = '';
        $podCode = '';
        if (!$isAirService && $this->pod) {
            $podParts = $this->extractPortInfo($this->pod);
            $podName = $podParts['name'];
            $podCode = $podParts['code'];
        }
        
        // Use database-agnostic case-insensitive matching
        // PostgreSQL supports ILIKE, SQLite/MySQL use LOWER() with LIKE
        $useIlike = DB::getDriverName() === 'pgsql';
        
        $today = now()->startOfDay();
        
        // Build base schedule query for POD extraction (without POD filtering)
        $baseScheduleQuery = $isAirService
            ? null
            : ShippingSchedule::where('is_active', true)
                ->where(function ($q) use ($today) {
                    $q->where(function ($query) use ($today) {
                        $query->whereNotNull('ets_pol')
                              ->where('ets_pol', '>=', $today);
                    })->orWhere(function ($query) use ($today) {
                        $query->whereNotNull('next_sailing_date')
                              ->where('next_sailing_date', '>=', $today);
                    });
                });
        
        // If POL is selected, filter schedules by POL to get relevant PODs
        if (!$isAirService && $baseScheduleQuery && $polName) {
            $baseScheduleQuery->whereHas('polPort', function ($portQuery) use ($polName, $polCode, $useIlike) {
                if ($useIlike) {
                    $portQuery->where(function($q) use ($polName, $polCode) {
                        $q->where('name', 'ILIKE', '%' . $polName . '%');
                        if ($polCode) {
                            $q->orWhere('code', 'ILIKE', '%' . $polCode . '%');
                        }
                    });
                } else {
                    $portQuery->where(function($q) use ($polName, $polCode) {
                        $q->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($polName) . '%']);
                        if ($polCode) {
                            $q->orWhereRaw('LOWER(code) LIKE ?', ['%' . strtolower($polCode) . '%']);
                        }
                    });
                }
            });
        }
        
        // Extract POD ports from active schedules
        $podPorts = collect();
        if ($isAirService) {
            // For air services, use airports
            $podPorts = Port::forAirports()->orderBy('name')->get();
        } elseif ($baseScheduleQuery) {
            // Get unique POD IDs from schedules
            $podIds = $baseScheduleQuery
                ->whereNotNull('pod_id')
                ->distinct()
                ->pluck('pod_id')
                ->filter()
                ->unique()
                ->values()
                ->toArray();
            
            if (!empty($podIds)) {
                $podPorts = Port::whereIn('id', $podIds)->orderBy('name')->get();
            }
        }
        
        // Fallback: if no PODs from schedules, use ports with active schedules
        if (!$isAirService && $podPorts->isEmpty()) {
            $podPorts = Port::withActivePodSchedules()->orderBy('name')->get();
        }
        
        // Build full schedule query for display (with POL/POD filtering if both selected)
        $schedules = $isAirService
            ? collect()
            : ShippingSchedule::where('is_active', true)
                ->where(function ($q) use ($today) {
                    $q->where(function ($query) use ($today) {
                        $query->whereNotNull('ets_pol')
                              ->where('ets_pol', '>=', $today);
                    })->orWhere(function ($query) use ($today) {
                        $query->whereNotNull('next_sailing_date')
                              ->where('next_sailing_date', '>=', $today);
                    });
                })
                ->when($polName && $podName, function ($q) use ($polName, $polCode, $podName, $podCode, $useIlike) {
                    // Filter schedules by route if POL/POD selected
                    $q->whereHas('polPort', function ($portQuery) use ($polName, $polCode, $useIlike) {
                        if ($useIlike) {
                            $portQuery->where(function($q) use ($polName, $polCode) {
                                $q->where('name', 'ILIKE', '%' . $polName . '%');
                                if ($polCode) {
                                    $q->orWhere('code', 'ILIKE', '%' . $polCode . '%');
                                }
                            });
                        } else {
                            $portQuery->where(function($q) use ($polName, $polCode) {
                                $q->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($polName) . '%']);
                                if ($polCode) {
                                    $q->orWhereRaw('LOWER(code) LIKE ?', ['%' . strtolower($polCode) . '%']);
                                }
                            });
                        }
                    })
                    ->whereHas('podPort', function ($portQuery) use ($podName, $podCode, $useIlike) {
                        if ($useIlike) {
                            $portQuery->where(function($q) use ($podName, $podCode) {
                                $q->where('name', 'ILIKE', '%' . $podName . '%');
                                if ($podCode) {
                                    $q->orWhere('code', 'ILIKE', '%' . $podCode . '%');
                                }
                            });
                        } else {
                            $portQuery->where(function($q) use ($podName, $podCode) {
                                $q->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($podName) . '%']);
                                if ($podCode) {
                                    $q->orWhereRaw('LOWER(code) LIKE ?', ['%' . strtolower($podCode) . '%']);
                                }
                            });
                        }
                    });
                })
                ->orderBy('next_sailing_date', 'asc')
                ->orderBy('ets_pol', 'asc')
                ->get();
        
        $serviceTypes = config('quotation.simple_service_types', []);
        
        [$polPortsFormatted, $podPortsFormatted] = $this->formatPortOptions($isAirService, $polPorts, $podPorts);

        $polPlaceholder = $isAirService ? 'Search or type any airport...' : 'Search or type any port...';
        $podPlaceholder = $isAirService ? 'Search or type any airport...' : 'Search or type any port...';
        $portsEnabled = !empty($this->simple_service_type);

        $this->dispatch(
            'quotation-ports-updated',
            polOptions: $polPortsFormatted,
            podOptions: $podPortsFormatted,
            isAir: $isAirService,
            portsEnabled: $portsEnabled,
            polPlaceholder: $polPlaceholder,
            podPlaceholder: $podPlaceholder,
        );
        
        // Ensure quotation is fresh with relationships for child components
        // Load both selectedSchedule.carrier and commodityItems for proper detection
        if ($this->quotation) {
            $this->quotation = $this->quotation->fresh(['selectedSchedule.carrier', 'commodityItems']);
        }
        
        // Update showArticles flag based on current state (check in render to ensure it's always up-to-date)
        // This is especially important when using wire:ignore for POL/POD inputs
        $this->updateShowArticles();
        
        return view('livewire.customer.quotation-creator', compact(
            'isAirService',
            'schedules',
            'serviceTypes',
            'polPortsFormatted',
            'podPortsFormatted',
            'polPlaceholder',
            'podPlaceholder',
            'portsEnabled'
        ));
    }

    protected function handleServiceTypeChanged(): void
    {
        $this->pol = '';
        $this->pol_port_id = null;
        $this->pod = '';
        $this->pod_port_id = null;
        $this->por = '';
        $this->fdest = '';
        $this->in_transit_to = '';
        $this->selected_schedule_id = null;
    }

    protected function isAirService(): bool
    {
        return $this->simple_service_type === 'AIR';
    }

    /**
     * Get service category (sea or air) from simple service type
     */
    protected function getServiceCategory(?string $simpleServiceType): string
    {
        if (empty($simpleServiceType)) {
            return 'sea'; // Default to sea
        }
        
        // AIR is air, everything else starting with SEA_ is sea
        if ($simpleServiceType === 'AIR') {
            return 'air';
        }
        
        return 'sea';
    }

    protected function formatPortOptions(bool $isAirService, $polPorts, $podPorts): array
    {
        if ($isAirService) {
            $airports = \App\Models\Port::forAirports()
                ->orderBy('name')
                ->get();

            $formatted = $airports->mapWithKeys(function ($port) {
                $display = $port->getDisplayName();
                return [$display => $display];
            })->toArray();

            return [$formatted, $formatted];
        }

        $polFormatted = $polPorts->mapWithKeys(function ($port) {
            $display = $port->formatFull();
            return [$display => $display];
        })->toArray();

        $podFormatted = $podPorts->mapWithKeys(function ($port) {
            $display = $port->formatFull();
            return [$display => $display];
        })->toArray();

        return [$polFormatted, $podFormatted];
    }
}
