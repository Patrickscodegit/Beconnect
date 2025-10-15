<?php

namespace App\Http\Controllers;

use App\Models\QuotationRequest;
use App\Models\Port;
use App\Models\ShippingCarrier;
use App\Notifications\QuotationSubmittedNotification;
use App\Services\RobawsFieldGenerator;
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
            // Get filter options for the form
            // POL: European origins only (unified port system)
            $polPorts = Port::europeanOrigins()->orderBy('name')->get();
            // POD: Only ports with active schedules (prevents empty results)
            $podPorts = Port::withActivePodSchedules()->orderBy('name')->get();
            $carriers = ShippingCarrier::where('is_active', true)->orderBy('name')->get();
            
            // Service types from config
            $serviceTypes = config('quotation.service_types', []);
            
            // Pre-fill from URL parameters if available (from schedule page)
            $prefill = [
                'pol' => $request->get('pol'),
                'pod' => $request->get('pod'),
                'service_type' => $request->get('service_type'),
                'carrier' => $request->get('carrier'),
            ];

            // Format ports for display with country
            $polPortsFormatted = $polPorts->mapWithKeys(function ($port) {
                return [$port->name => $port->name . ' (' . $port->code . '), ' . $port->country];
            });
            $podPortsFormatted = $podPorts->mapWithKeys(function ($port) {
                return [$port->name => $port->name . ' (' . $port->code . '), ' . $port->country];
            });

            return view('public.quotations.create', compact(
                'polPorts', 
                'podPorts', 
                'polPortsFormatted',
                'podPortsFormatted',
                'carriers', 
                'serviceTypes',
                'prefill'
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
        
        // Debug logging
        \Log::info('📥 Quotation Request Received', [
            'has_commodity_items' => $request->has('commodity_items'),
            'commodity_items_value' => $request->input('commodity_items'),
            'quotation_mode' => $request->input('quotation_mode'),
            'cargo_description' => $request->input('cargo_description') ? 'Present' : 'Missing',
        ]);
        
        if ($validator->fails()) {
            \Log::warning('❌ Validation Failed', [
                'errors' => $validator->errors()->toArray()
            ]);
            
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
            'service_type' => 'required|string|in:' . implode(',', array_keys(config('quotation.service_types', []))),
            
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
            'service_type' => $request->service_type,
            'trade_direction' => $this->getDirectionFromServiceType($request->service_type),
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
