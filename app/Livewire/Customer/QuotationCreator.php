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
    public $simple_service_type = '';
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
    public bool $submitting = false;
    
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
                'contact_phone' => $user->phone ?? null,
                'client_email' => $user->email,
                'client_name' => $user->name,
                'client_tel' => $user->phone ?? null,
                'pricing_tier_id' => $user->pricing_tier_id ?? $this->getDefaultPricingTierId(),
                'customer_role' => $user->customer_role ?? 'RORO',
                'vat_rate' => 21.00,
                'pricing_currency' => 'EUR',
                // Required fields - will be updated when customer fills form
                'service_type' => 'RORO_EXPORT', // Default, will be updated
                'simple_service_type' => 'roro', // Default, will be updated
                'trade_direction' => 'export', // Derived from RORO_EXPORT
                'cargo_description' => 'Draft - being filled by customer', // Default, will be updated
                'pol' => '', // Will be filled by customer
                'pod' => '', // Will be filled by customer
                'routing' => [ // Will be updated as customer fills form
                    'por' => '',
                    'pol' => '',
                    'pod' => '',
                    'fdest' => '',
                ],
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
    
    // Auto-save when fields change
    public function updated($propertyName)
    {
        // Skip auto-save for file uploads (handled separately)
        if ($propertyName === 'supporting_files') {
            return;
        }
        
        // Map simple service type to actual service type
        if ($propertyName === 'simple_service_type') {
            $this->service_type = config("quotation.simple_service_types.{$this->simple_service_type}.default_service_type");
        }
        
        // Save to draft quotation
        if ($this->quotation) {
            $this->quotation->update([
                'pol' => $this->pol,
                'pod' => $this->pod,
                'por' => $this->por,
                'fdest' => $this->fdest,
                'routing' => [
                    'por' => $this->por,
                    'pol' => $this->pol,
                    'pod' => $this->pod,
                    'fdest' => $this->fdest,
                ],
                'service_type' => $this->service_type,
                'simple_service_type' => $this->simple_service_type,
                'trade_direction' => $this->getDirectionFromServiceType($this->service_type),
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
                $this->dispatch('quotationUpdated');
            }
            
            Log::debug('Draft quotation auto-saved', [
                'quotation_id' => $this->quotationId,
                'field' => $propertyName,
                'show_articles' => $this->showArticles,
            ]);
        }
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
        
        // Validate
        $this->validate([
            'pol' => 'required|string|max:255',
            'pod' => 'required|string|max:255',
            'simple_service_type' => 'required|string',
            'cargo_description' => 'required|string',
        ]);
        
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
        ]);
        
        // Redirect to show page
        return redirect()
            ->route('customer.quotations.show', $this->quotation)
            ->with('success', 'Quotation submitted for review! Our team will respond within 24 hours.');
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
        $polPorts = Port::europeanOrigins()->orderBy('name')->get();
        $podPorts = Port::withActivePodSchedules()->orderBy('name')->get();
        $schedules = ShippingSchedule::where('is_active', true)
            ->when($this->pol && $this->pod, function ($q) {
                // Filter schedules by route if POL/POD selected
                $q->whereHas('polPort', function ($portQuery) {
                    $portQuery->where('name', 'ILIKE', '%' . $this->pol . '%');
                })
                ->whereHas('podPort', function ($portQuery) {
                    $portQuery->where('name', 'ILIKE', '%' . $this->pod . '%');
                });
            })
            ->orderBy('next_sailing_date', 'asc')
            ->orderBy('ets_pol', 'asc')
            ->get();
        
        $serviceTypes = config('quotation.simple_service_types', []);
        
        // Format ports for autocomplete
        $polPortsFormatted = $polPorts->mapWithKeys(function ($port) {
            return [$port->code => $port->name . ', ' . $port->country];
        })->toArray();
        
        $podPortsFormatted = $podPorts->mapWithKeys(function ($port) {
            return [$port->code => $port->name . ', ' . $port->country];
        })->toArray();
        
        return view('livewire.customer.quotation-creator', compact(
            'polPorts',
            'podPorts',
            'schedules',
            'serviceTypes',
            'polPortsFormatted',
            'podPortsFormatted'
        ));
    }
}
