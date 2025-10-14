<?php

namespace App\Http\Controllers;

use App\Models\ShippingSchedule;
use App\Models\ShippingCarrier;
use App\Models\Port;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CustomerScheduleController extends Controller
{
    /**
     * Show schedule list for customers (includes pricing info)
     */
    public function index(Request $request)
    {
        // Create cache key from request parameters only (not the full request object)
        $cacheKey = 'customer_schedules_' . md5(json_encode($request->only(['pol', 'pod', 'carrier', 'service_type'])));
        
        // Get only the parameters we need for caching
        $requestParams = $request->only(['pol', 'pod', 'carrier', 'service_type']);
        
        $data = Cache::remember($cacheKey, 300, function () use ($requestParams) {
            // Create a new request with only the needed parameters
            $mockRequest = new \Illuminate\Http\Request($requestParams);
            return $this->buildScheduleData($mockRequest);
        });
        
        return view('customer.schedules.index', $data);
    }

    /**
     * Show schedule details for customers
     */
    public function show(ShippingSchedule $schedule)
    {
        $schedule->load(['carrier', 'polPort', 'podPort']);
        
        // Pre-fill data for quotation request
        $quotationPrefill = [
            'pol' => $schedule->polPort->name,
            'pod' => $schedule->podPort->name,
            'service_type' => $schedule->carrier->service_types[0] ?? null,
            'carrier' => $schedule->carrier->code,
            'selected_schedule_id' => $schedule->id,
        ];
        
        return view('customer.schedules.show', compact('schedule', 'quotationPrefill'));
    }

    /**
     * Build schedule data with filters
     */
    protected function buildScheduleData(Request $request)
    {
        $query = ShippingSchedule::query()
            ->where('is_active', true)
            ->with(['carrier', 'polPort', 'podPort']);

        // Apply filters
        if ($request->filled('pol')) {
            $query->whereHas('polPort', function($q) use ($request) {
                $q->where('code', $request->pol);
            });
        }

        if ($request->filled('pod')) {
            $query->whereHas('podPort', function($q) use ($request) {
                $q->where('code', $request->pod);
            });
        }

        if ($request->filled('carrier')) {
            $query->where('carrier_id', $request->carrier);
        }

        if ($request->filled('service_type')) {
            $query->whereHas('carrier', function($q) use ($request) {
                $q->whereJsonContains('service_types', $request->service_type);
            });
        }

        // Get schedules
        $schedules = $query->orderBy('next_sailing_date', 'asc')
            ->paginate(12)
            ->appends($request->query());

        // Get filter options (unified port system)
        $polPorts = Port::europeanOrigins()->orderBy('name')->get();
        $podPorts = Port::withActivePodSchedules()->orderBy('name')->get();
        $carriers = ShippingCarrier::where('is_active', true)->orderBy('name')->get();
        $serviceTypes = config('quotation.service_types', []);

        // Format ports for display with country
        $polPortsFormatted = $polPorts->mapWithKeys(function ($port) {
            return [$port->name => $port->name . ' (' . $port->code . '), ' . $port->country];
        });
        $podPortsFormatted = $podPorts->mapWithKeys(function ($port) {
            return [$port->name => $port->name . ' (' . $port->code . '), ' . $port->country];
        });

        // Create filters array for the view
        $filters = [
            'pol' => $request->get('pol'),
            'pod' => $request->get('pod'),
            'carrier' => $request->get('carrier'),
            'service_type' => $request->get('service_type'),
        ];

        return compact('schedules', 'polPorts', 'podPorts', 'polPortsFormatted', 'podPortsFormatted', 'carriers', 'serviceTypes', 'request', 'filters');
    }
}

