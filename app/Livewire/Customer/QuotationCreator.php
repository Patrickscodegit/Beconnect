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
        
        // Cast selected_schedule_id to int if it's not null/empty (handle string "0" or empty string)
        if ($propertyName === 'selected_schedule_id') {
            $this->selected_schedule_id = $this->selected_schedule_id ? (int) $this->selected_schedule_id : null;
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
            
            // Refresh quotation to ensure relationships are loaded
            $this->quotation = $this->quotation->fresh(['selectedSchedule.carrier']);
            
            // Check if we should show articles (use trim to handle whitespace-only strings)
            $polFilled = !empty(trim($this->pol));
            $podFilled = !empty(trim($this->pod));
            $scheduleSelected = $this->selected_schedule_id !== null && $this->selected_schedule_id > 0;
            
            $this->showArticles = $polFilled && $podFilled && $scheduleSelected;
            
            // Emit event to SmartArticleSelector to reload
            if ($this->showArticles) {
                $this->dispatch('quotationUpdated');
            }
            
            // INFO-level logging for production debugging
            Log::info('QuotationCreator state updated', [
                'quotation_id' => $this->quotationId,
                'field' => $propertyName,
                'pol' => $this->pol,
                'pod' => $this->pod,
                'selected_schedule_id' => $this->selected_schedule_id,
                'pol_filled' => $polFilled,
                'pod_filled' => $podFilled,
                'schedule_selected' => $scheduleSelected,
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
        $polPorts = Port::europeanOrigins()->orderBy('name')->get();
        $podPorts = Port::withActivePodSchedules()->orderBy('name')->get();
        
        // Extract port name and code from formats like:
        // "Dakar (DKR), Senegal" or "Antwerp (ANR), Belgium"
        // "Dakar, Senegal" or just "Dakar"
        $polName = '';
        $polCode = '';
        if ($this->pol) {
            $polParts = $this->extractPortInfo($this->pol);
            $polName = $polParts['name'];
            $polCode = $polParts['code'];
        }
        
        $podName = '';
        $podCode = '';
        if ($this->pod) {
            $podParts = $this->extractPortInfo($this->pod);
            $podName = $podParts['name'];
            $podCode = $podParts['code'];
        }
        
        // Use database-agnostic case-insensitive matching
        // PostgreSQL supports ILIKE, SQLite/MySQL use LOWER() with LIKE
        $useIlike = DB::getDriverName() === 'pgsql';
        
        $schedules = ShippingSchedule::where('is_active', true)
            ->when($polName && $podName, function ($q) use ($polName, $polCode, $podName, $podCode, $useIlike) {
                // Filter schedules by route if POL/POD selected
                // Match on port name OR code (handles "City (CODE), Country" format)
                $q->whereHas('polPort', function ($portQuery) use ($polName, $polCode, $useIlike) {
                    if ($useIlike) {
                        // PostgreSQL: Use ILIKE
                        $portQuery->where(function($q) use ($polName, $polCode) {
                            $q->where('name', 'ILIKE', '%' . $polName . '%');
                            if ($polCode) {
                                $q->orWhere('code', 'ILIKE', '%' . $polCode . '%');
                            }
                        });
                    } else {
                        // SQLite/MySQL: Use LOWER() with LIKE
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
                        // PostgreSQL: Use ILIKE
                        $portQuery->where(function($q) use ($podName, $podCode) {
                            $q->where('name', 'ILIKE', '%' . $podName . '%');
                            if ($podCode) {
                                $q->orWhere('code', 'ILIKE', '%' . $podCode . '%');
                            }
                        });
                    } else {
                        // SQLite/MySQL: Use LOWER() with LIKE
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
        
        // Format ports for autocomplete JavaScript (standard format: "City (CODE), Country")
        $polPortsFormatted = $polPorts->mapWithKeys(function ($port) {
            $display = $port->formatFull();
            return [$display => $display];
        })->toArray();
        
        $podPortsFormatted = $podPorts->mapWithKeys(function ($port) {
            $display = $port->formatFull();
            return [$display => $display];
        })->toArray();
        
        // Ensure quotation is fresh with relationships for child components
        if ($this->quotation) {
            $this->quotation = $this->quotation->fresh(['selectedSchedule.carrier']);
        }
        
        // Update showArticles flag based on current state (check in render to ensure it's always up-to-date)
        // This is especially important when using wire:ignore for POL/POD inputs
        $polFilled = !empty(trim($this->pol));
        $podFilled = !empty(trim($this->pod));
        $scheduleSelected = $this->selected_schedule_id !== null && $this->selected_schedule_id > 0;
        $this->showArticles = $polFilled && $podFilled && $scheduleSelected;
        
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
