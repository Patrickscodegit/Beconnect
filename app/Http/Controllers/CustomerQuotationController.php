<?php

namespace App\Http\Controllers;

use App\Models\QuotationRequest;
use App\Models\Port;
use App\Models\ShippingCarrier;
use App\Models\ShippingSchedule;
use App\Notifications\QuotationSubmittedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CustomerQuotationController extends Controller
{
    /**
     * Show all customer's quotations
     */
    public function index()
    {
        $user = auth()->user();
        
        $quotations = QuotationRequest::where(function($q) use ($user) {
                $q->where('contact_email', $user->email)
                  ->orWhere('client_email', $user->email);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(10);
            
        return view('customer.quotations.index', compact('quotations'));
    }

    /**
     * Show quotation request form
     */
    public function create(Request $request)
    {
        $user = auth()->user();
        
        // Get filter options
        $polPorts = Port::active()->orderBy('name')->get();
        $podPorts = Port::active()->orderBy('name')->get();
        $carriers = ShippingCarrier::where('is_active', true)->orderBy('name')->get();
        
        $serviceTypes = config('quotation.service_types', []);
        $customerRoles = config('quotation.customer_roles', []);
        $customerTypes = config('quotation.customer_types', []);
        
        // Pre-fill from URL parameters (from schedule page)
        $prefill = [
            'pol' => $request->get('pol'),
            'pod' => $request->get('pod'),
            'service_type' => $request->get('service_type'),
            'carrier' => $request->get('carrier'),
            // Pre-fill customer data from auth user
            'contact_name' => $user->name,
            'contact_email' => $user->email,
        ];
        
        return view('customer.quotations.create', compact(
            'polPorts',
            'podPorts',
            'carriers',
            'serviceTypes',
            'customerRoles',
            'customerTypes',
            'prefill',
            'user'
        ));
    }

    /**
     * Store customer quotation request
     */
    public function store(Request $request)
    {
        $user = auth()->user();
        
        // Validate
        $validator = $this->validateQuotationRequest($request);
        
        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            // Create quotation
            $quotationRequest = $this->createQuotationRequest($request, $user);
            
            // Handle file uploads
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

            return redirect()->route('customer.quotations.show', $quotationRequest)
                ->with('success', 'Your quotation request has been submitted successfully. We will review and respond within 24 hours.');

        } catch (\Exception $e) {
            \Log::error('Customer quotation creation failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'request_data' => $request->except(['supporting_files'])
            ]);

            return redirect()->back()
                ->with('error', 'There was an error submitting your request. Please try again.')
                ->withInput();
        }
    }

    /**
     * Show quotation details
     */
    public function show(QuotationRequest $quotationRequest)
    {
        $user = auth()->user();
        
        // Verify ownership
        if ($quotationRequest->contact_email !== $user->email && 
            $quotationRequest->client_email !== $user->email) {
            abort(403, 'Unauthorized access to this quotation');
        }

        return view('customer.quotations.show', compact('quotationRequest'));
    }

    /**
     * Validate quotation request
     */
    protected function validateQuotationRequest(Request $request)
    {
        return Validator::make($request->all(), [
            // Route Information
            'pol' => 'required|string|max:255',
            'pod' => 'required|string|max:255',
            'por' => 'nullable|string|max:255',
            'fdest' => 'nullable|string|max:255',
            
            // Service Information
            'service_type' => 'required|string|in:' . implode(',', array_keys(config('quotation.service_types', []))),
            'trade_direction' => 'required|string|in:import,export',
            'customer_type' => 'required|string|in:' . implode(',', array_keys(config('quotation.customer_types', []))),
            'customer_role' => 'required|string|in:' . implode(',', array_keys(config('quotation.customer_roles', []))),
            
            // Cargo Information
            'cargo_description' => 'required|string|max:1000',
            'commodity_type' => 'required|string|max:255',
            'cargo_weight' => 'nullable|numeric|min:0',
            'cargo_volume' => 'nullable|numeric|min:0',
            'cargo_dimensions' => 'nullable|string|max:255',
            'cargo_value' => 'nullable|numeric|min:0',
            
            // Additional Information
            'special_requirements' => 'nullable|string|max:1000',
            'preferred_departure_date' => 'nullable|date|after:today',
            'customer_reference' => 'nullable|string|max:255',
            
            // File Uploads
            'supporting_files' => 'nullable|array|max:5',
            'supporting_files.*' => 'file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,txt',
        ]);
    }

    /**
     * Create quotation request
     */
    protected function createQuotationRequest(Request $request, $user)
    {
        $data = [
            'source' => 'customer',
            'requester_type' => 'customer',
            
            // Contact fields (person) - from auth user
            'contact_name' => $user->name,
            'contact_email' => $user->email,
            'contact_phone' => $request->contact_phone,
            'contact_company' => $request->contact_company,
            'contact_function' => $request->contact_function,
            
            // Client fields (company)
            'client_name' => $request->contact_company ?: $user->name,
            'client_email' => $user->email,
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
            'trade_direction' => $request->trade_direction,
            'customer_type' => $request->customer_type,
            'customer_role' => $request->customer_role,
            
            // Cargo
            'cargo_description' => $request->cargo_description,
            'commodity_type' => $request->commodity_type,
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
            'preferred_carrier' => $request->preferred_carrier,
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
                    'file_type' => 'cargo_info',
                    'uploaded_by' => auth()->id(),
                ]);
            }
        }
    }
}

