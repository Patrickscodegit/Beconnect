<?php

namespace App\Http\Controllers;

use App\Models\QuotationRequest;
use App\Models\Port;
use App\Models\ShippingCarrier;
use App\Models\Intake;
use App\Notifications\QuotationSubmittedNotification;
use App\Services\RobawsFieldGenerator;
use App\Services\Commodity\CommodityMappingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProspectQuotationController extends Controller
{

    /**
     * Show the prospect quotation request form
     */
    public function create(Request $request)
    {
        try {
            // Get filter options for the form (aligned with Filament QuotationRequestResource)
            // POL: European origins for seaports, airports for airfreight
            $polPorts = Port::europeanOrigins()->orderBy('name')->get();
            // POD: Ports with active schedules for seaports, airports for airfreight
            $podPorts = Port::withActivePodSchedules()->orderBy('name')->get();
            $carriers = ShippingCarrier::where('is_active', true)->orderBy('name')->get();
            
            // Get airports for airfreight services
            $airports = collect(config('airports', []))->map(function ($airport) {
                return [
                    'code' => $airport['code'],
                    'name' => $airport['name'],
                    'country' => $airport['country'],
                    'full_name' => $airport['full_name'],
                ];
            })->values()->all();
            
            // Service types from config
            $serviceTypes = config('quotation.service_types', []);
            
            // Check if coming from intake
            $intake = null;
            $commodityItems = [];
            $intakeData = [];
            
            if ($request->has('intake_id')) {
                $intake = Intake::find($request->get('intake_id'));
                
                if ($intake) {
                    // Get commodity items from extraction data
                    $commodityMappingService = app(CommodityMappingService::class);
                    $commodityItems = $commodityMappingService->mapFromIntake($intake);
                    
                    // Get extraction data for pre-filling form
                    $extractionData = $intake->is_multi_document && $intake->aggregated_extraction_data
                        ? $intake->aggregated_extraction_data
                        : ($intake->documents()->first()?->extraction_data ?? []);
                    
                    if (!empty($extractionData)) {
                        $rawData = $extractionData['raw_data'] ?? [];
                        $documentData = $extractionData['document_data'] ?? [];
                        
                        // Extract contact data
                        $contact = $documentData['contact'] ?? $extractionData['contact'] ?? $rawData['contact'] ?? [];
                        $shipping = $documentData['shipping'] ?? $extractionData['shipping'] ?? $rawData['shipping'] ?? [];
                        
                        $intakeData = [
                            'contact_name' => $contact['name'] ?? $intake->customer_name ?? null,
                            'contact_email' => $contact['email'] ?? $intake->contact_email ?? null,
                            'contact_phone' => $contact['phone'] ?? $intake->contact_phone ?? null,
                            'pol' => $shipping['origin'] ?? $rawData['pol'] ?? null,
                            'pod' => $shipping['destination'] ?? $rawData['pod'] ?? null,
                            'service_type' => $intake->service_type,
                        ];
                    }
                }
            }
            
            // Pre-fill from URL parameters (from schedule page) or intake data
            $prefill = [
                'pol' => $request->get('pol') ?? $intakeData['pol'] ?? null,
                'pod' => $request->get('pod') ?? $intakeData['pod'] ?? null,
                'service_type' => $request->get('service_type') ?? $intakeData['service_type'] ?? null,
                'carrier' => $request->get('carrier'),
                'contact_name' => $intakeData['contact_name'] ?? null,
                'contact_email' => $intakeData['contact_email'] ?? null,
                'contact_phone' => $intakeData['contact_phone'] ?? null,
            ];

            // Format ports for display with country (standard format: "City (CODE), Country")
            $polPortsFormatted = $polPorts->mapWithKeys(function ($port) {
                return [$port->name => $port->formatFull()];
            });
            $podPortsFormatted = $podPorts->mapWithKeys(function ($port) {
                return [$port->name => $port->formatFull()];
            });
            
            // Format airports for display
            $airportsFormatted = collect($airports)->mapWithKeys(function ($airport) {
                return [$airport['name'] => $airport['full_name']];
            });

            return view('public.quotations.create', compact(
                'polPorts', 
                'podPorts', 
                'polPortsFormatted',
                'podPortsFormatted',
                'airports',
                'airportsFormatted',
                'carriers', 
                'serviceTypes',
                'prefill',
                'commodityItems',
                'intake'
            ));
        } catch (\Exception $e) {
            \Log::error('ProspectQuotationController::create error: ' . $e->getMessage());
            return response('Error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Store the prospect quotation request
     */
    public function store(Request $request)
    {
        // Validate the request
        $validator = $this->validateQuotationRequest($request);
        
        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            // Create the quotation request
            $quotationRequest = $this->createQuotationRequest($request);
            
            // Handle commodity items if present
            if ($request->filled('commodity_items')) {
                $this->handleCommodityItems($quotationRequest, $request);
                
                // Generate Robaws fields from commodity items
                $fieldGenerator = new RobawsFieldGenerator();
                $fieldGenerator->generateAndUpdateFields($quotationRequest);
            }
            
            // Handle file uploads if any
            if ($request->hasFile('supporting_files')) {
                $this->handleFileUploads($quotationRequest, $request->file('supporting_files'));
            }

            // Notify team about new quotation
            try {
                Notification::route('mail', config('mail.team_address', 'info@belgaco.be'))
                    ->notify(new QuotationSubmittedNotification($quotationRequest));
            } catch (\Exception $e) {
                \Log::warning('Failed to send quotation submitted notification', [
                    'quotation_id' => $quotationRequest->id,
                    'error' => $e->getMessage()
                ]);
                // Don't fail the request if email fails
            }
            
            return redirect()->route('public.quotations.confirmation', $quotationRequest)
                ->with('success', 'Your quotation request has been submitted successfully. We will contact you within 24 hours.');

        } catch (\Exception $e) {
            \Log::error('Prospect quotation creation failed', [
                'error' => $e->getMessage(),
                'request_data' => $request->except(['supporting_files'])
            ]);

            return redirect()->back()
                ->with('error', 'There was an error submitting your request. Please try again or contact us directly.')
                ->withInput();
        }
    }

    /**
     * Show confirmation page after submission
     */
    public function confirmation(QuotationRequest $quotationRequest)
    {
        // Ensure this is a prospect request and not accessed by unauthorized users
        if ($quotationRequest->source !== 'prospect') {
            abort(404);
        }

        return view('public.quotations.confirmation', compact('quotationRequest'));
    }

    /**
     * Show quotation status (for tracking)
     */
    public function status(Request $request)
    {
        $requestNumber = $request->get('request_number');
        
        if (!$requestNumber) {
            return view('public.quotations.status-form');
        }

        $quotationRequest = QuotationRequest::where('request_number', $requestNumber)
            ->where('source', 'prospect')
            ->first();

        if (!$quotationRequest) {
            return view('public.quotations.status-form')
                ->with('error', 'Quotation request not found. Please check your request number.');
        }

        return view('public.quotations.status', compact('quotationRequest'));
    }

    /**
     * Validate the quotation request
     */
    protected function validateQuotationRequest(Request $request)
    {
        $rules = [
            // Contact Information
            'contact_name' => 'required|string|max:255',
            'contact_email' => 'required|email|max:255',
            'contact_phone' => 'required|string|max:50',
            'contact_company' => 'nullable|string|max:255',
            
            // Route Information
            'pol' => 'required|string|max:255',
            'pod' => 'required|string|max:255',
            'por' => 'nullable|string|max:255',
            'fdest' => 'nullable|string|max:255',
            
            // Service Information
            'simple_service_type' => 'required|string|in:' . implode(',', array_keys(config('quotation.simple_service_types', []))),
            
            // Legacy Cargo Information (optional if commodity_items provided)
            'cargo_description' => 'required_without:commodity_items|nullable|string|max:1000',
            'commodity_type' => 'nullable|string|max:255',
            'cargo_weight' => 'nullable|numeric|min:0',
            'cargo_volume' => 'nullable|numeric|min:0',
            'cargo_dimensions' => 'nullable|string|max:255',
            'cargo_value' => 'nullable|numeric|min:0',
            
            // New Multi-Commodity System
            'commodity_items' => 'nullable|json',
            'unit_system' => 'nullable|string|in:metric,us',
            
            // Additional Information
            'special_requirements' => 'nullable|string|max:1000',
            'preferred_departure_date' => 'nullable|date|after:today',
            'customer_reference' => 'nullable|string|max:255',
            'selected_schedule_id' => 'nullable|exists:shipping_schedules,id',
            
            // File Uploads
            'supporting_files' => 'nullable|array|max:5',
            'supporting_files.*' => 'file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,txt',
            
            // Terms and Privacy
            'terms_accepted' => 'required|accepted',
            'privacy_policy_accepted' => 'required|accepted',
        ];

        return Validator::make($request->all(), $rules, [
            'terms_accepted.accepted' => 'You must accept the terms and conditions.',
            'privacy_policy_accepted.accepted' => 'You must accept the privacy policy.',
            'supporting_files.max' => 'You can upload a maximum of 5 files.',
            'supporting_files.*.max' => 'Each file must be smaller than 10MB.',
            'supporting_files.*.mimes' => 'Only PDF, images, and office documents are allowed.',
        ]);
    }

    /**
     * Create the quotation request
     */
    protected function createQuotationRequest(Request $request)
    {
        // Map simple service type to actual service type
        $simpleServiceType = $request->simple_service_type;
        $defaultServiceType = config("quotation.simple_service_types.{$simpleServiceType}.default_service_type", 'RORO_EXPORT');
        
        $data = [
            'source' => 'prospect',
            'requester_type' => 'prospect',
            
            // Contact fields (person)
            'contact_name' => $request->contact_name,
            'contact_email' => $request->contact_email,
            'contact_phone' => $request->contact_phone,
            'contact_company' => $request->contact_company,
            
            // Client fields (company) - default to contact info for prospects
            'client_name' => $request->contact_company ?: $request->contact_name,
            'client_email' => $request->contact_email,
            'client_tel' => $request->contact_phone,
            
            // Route
            'pol' => $request->pol,
            'pod' => $request->pod,
            'por' => $request->por,
            'fdest' => $request->fdest,
            'routing' => [
                'por' => $request->por,
                'pol' => $request->pol,
                'pod' => $request->pod,
                'fdest' => $request->fdest,
            ],
            
            // Service
            'simple_service_type' => $simpleServiceType, // Customer's choice
            'service_type' => $defaultServiceType, // Auto-mapped, team can override
            'trade_direction' => $this->getDirectionFromServiceType($defaultServiceType),
            'customer_type' => 'GENERAL', // Set by Belgaco team in admin panel
            'customer_role' => 'CONSIGNEE', // Set by Belgaco team in admin panel
            
            // Cargo
            'cargo_description' => $request->cargo_description,
            'commodity_type' => $request->commodity_type, // Quick Quote mode
            'cargo_details' => [
                'weight' => $request->cargo_weight,
                'volume' => $request->cargo_volume,
                'dimensions' => $request->cargo_dimensions,
                'value' => $request->cargo_value,
                'special_requirements' => $request->special_requirements,
            ],
            
            // Additional
            'preferred_departure_date' => $request->preferred_departure_date,
            'customer_reference' => $request->customer_reference,
            'selected_schedule_id' => $request->selected_schedule_id,
            'pricing_currency' => 'EUR',
            'robaws_sync_status' => 'pending',
            'status' => 'pending',
        ];

        return QuotationRequest::create($data);
    }

    /**
     * Handle file uploads
     */
    protected function handleFileUploads(QuotationRequest $quotationRequest, array $files)
    {
        foreach ($files as $file) {
            if ($file->isValid()) {
                // Generate unique filename
                $filename = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
                
                // Store file
                $path = $file->storeAs(
                    'quotation_files/' . $quotationRequest->id,
                    $filename,
                    'public'
                );

                // Create file record
                $quotationRequest->files()->create([
                    'original_filename' => $file->getClientOriginalName(),
                    'filename' => $filename,
                    'file_path' => $path,
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'file_type' => 'other', // Default type
                    'uploaded_by' => null, // Prospect uploads (no user ID)
                ]);
            }
        }
    }

    /**
     * Handle commodity items creation
     */
    protected function handleCommodityItems(QuotationRequest $quotationRequest, Request $request)
    {
        $commodityItems = json_decode($request->commodity_items, true);
        $unitSystem = $request->unit_system ?? 'metric';
        
        if (!empty($commodityItems) && is_array($commodityItems)) {
            foreach ($commodityItems as $index => $item) {
                // Convert to metric if needed
                $processedItem = $this->convertToMetric($item, $unitSystem);
                $processedItem['line_number'] = $index + 1;
                $processedItem['quotation_request_id'] = $quotationRequest->id;
                
                // Clean up empty strings for decimal fields - convert to null
                $decimalFields = ['wheelbase_cm', 'length_cm', 'width_cm', 'height_cm', 'cbm', 'weight_kg', 'bruto_weight_kg', 'netto_weight_kg', 'unit_price', 'line_total'];
                foreach ($decimalFields as $field) {
                    if (isset($processedItem[$field]) && $processedItem[$field] === '') {
                        $processedItem[$field] = null;
                    }
                }
                
                // Auto-calculate CBM if dimensions present
                if (isset($processedItem['length_cm'], $processedItem['width_cm'], $processedItem['height_cm']) && 
                    $processedItem['length_cm'] && $processedItem['width_cm'] && $processedItem['height_cm']) {
                    $processedItem['cbm'] = ($processedItem['length_cm'] / 100) * 
                                           ($processedItem['width_cm'] / 100) * 
                                           ($processedItem['height_cm'] / 100);
                }
                
                // Handle relationship fields
                // Set default relationship_type if not provided
                $processedItem['relationship_type'] = $processedItem['relationship_type'] ?? 'separate';
                
                // Ensure related_item_id is null if relationship_type is 'separate'
                if ($processedItem['relationship_type'] === 'separate') {
                    $processedItem['related_item_id'] = null;
                }
                
                // Note: related_item_id should reference an item that was already created
                // If it's a temporary ID or index, we'll need to resolve it after all items are created
                // For now, we'll save it as-is if it's numeric, otherwise set to null
                if (isset($processedItem['related_item_id']) && !is_numeric($processedItem['related_item_id'])) {
                    $processedItem['related_item_id'] = null;
                }
                
                $quotationRequest->commodityItems()->create($processedItem);
            }
            
            // Update quotation with item count
            $quotationRequest->update([
                'total_commodity_items' => count($commodityItems)
            ]);
        }
    }

    /**
     * Convert dimensions and weights from US to metric if needed
     */
    protected function convertToMetric(array $item, string $unitSystem): array
    {
        if ($unitSystem === 'us') {
            // Convert dimensions: inches to cm
            if (isset($item['length_cm'])) {
                $item['length_cm'] = round($item['length_cm'] * 2.54, 2);
            }
            if (isset($item['width_cm'])) {
                $item['width_cm'] = round($item['width_cm'] * 2.54, 2);
            }
            if (isset($item['height_cm'])) {
                $item['height_cm'] = round($item['height_cm'] * 2.54, 2);
            }
            if (isset($item['wheelbase_cm'])) {
                $item['wheelbase_cm'] = round($item['wheelbase_cm'] * 2.54, 2);
            }
            
            // Convert weights: lbs to kg
            if (isset($item['weight_kg'])) {
                $item['weight_kg'] = round($item['weight_kg'] * 0.453592, 2);
            }
            if (isset($item['bruto_weight_kg'])) {
                $item['bruto_weight_kg'] = round($item['bruto_weight_kg'] * 0.453592, 2);
            }
            if (isset($item['netto_weight_kg'])) {
                $item['netto_weight_kg'] = round($item['netto_weight_kg'] * 0.453592, 2);
            }
        }
        
        return $item;
    }

    /**
     * Derive trade direction from service type
     */
    private function getDirectionFromServiceType(string $serviceType): string
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
}
