<?php

namespace App\Http\Controllers;

use App\Models\ShippingSchedule;
use App\Models\Port;
use App\Models\ShippingCarrier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class PublicScheduleController extends Controller
{
    /**
     * Display a listing of public schedules
     */
    public function index(Request $request): View
    {
        // Build cache key from query parameters
        $cacheKey = 'public_schedules_' . md5(json_encode($request->only(['pol', 'pod', 'carrier', 'service_type', 'page'])));
        
        // Cache for 15 minutes
        $data = Cache::remember($cacheKey, 900, function () use ($request) {
            return $this->buildScheduleData($request);
        });
        
        return view('public.schedules.index', $data);
    }

    /**
     * Display a single schedule
     */
    public function show(ShippingSchedule $schedule): View
    {
        // Only show active schedules
        if (!$schedule->is_active) {
            abort(404, 'Schedule not found or no longer available.');
        }
        
        // Eager load relationships
        $schedule->load(['polPort', 'podPort', 'carrier']);
        
        // Cache schedule details for 15 minutes
        $cacheKey = 'public_schedule_' . $schedule->id;
        $scheduleData = Cache::remember($cacheKey, 900, function () use ($schedule) {
            return [
                'schedule' => $schedule,
                'frequency_display' => $schedule->accurate_frequency_display,
                'transit_time_display' => $schedule->transit_time_display,
            ];
        });
        
        return view('public.schedules.show', $scheduleData);
    }

    /**
     * Build schedule data with filters
     */
    protected function buildScheduleData(Request $request): array
    {
        $query = ShippingSchedule::with(['polPort', 'podPort', 'carrier'])
            ->active();

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

        // Order by next sailing date
        $schedules = $query->orderBy('next_sailing_date')
                          ->orderBy('vessel_name')
                          ->paginate(20);

        // Get filter options
        $polPorts = $this->getPolPorts();
        $podPorts = $this->getPodPorts();
        $carriers = ShippingCarrier::where('is_active', true)->orderBy('name')->get();
        $serviceTypes = $this->getServiceTypes();

        // Format ports for display with country
        $polPortsFormatted = $polPorts->mapWithKeys(function ($port) {
            return [$port->name => $port->name . ' (' . $port->code . '), ' . $port->country];
        });
        $podPortsFormatted = $podPorts->mapWithKeys(function ($port) {
            return [$port->name => $port->name . ' (' . $port->code . '), ' . $port->country];
        });

        return [
            'schedules' => $schedules,
            'polPorts' => $polPorts,
            'podPorts' => $podPorts,
            'polPortsFormatted' => $polPortsFormatted,
            'podPortsFormatted' => $podPortsFormatted,
            'carriers' => $carriers,
            'serviceTypes' => $serviceTypes,
            'filters' => [
                'pol' => $request->get('pol', ''),
                'pod' => $request->get('pod', ''),
                'carrier' => $request->get('carrier', ''),
                'service_type' => $request->get('service_type', ''),
            ],
        ];
    }

    /**
     * Get POL (Port of Loading) ports - European origins only
     */
    protected function getPolPorts()
    {
        return Port::europeanOrigins()->orderBy('name')->get();
    }

    /**
     * Get POD (Port of Discharge) ports - Only ports with active schedules
     */
    protected function getPodPorts()
    {
        return Port::withActivePodSchedules()->orderBy('name')->get();
    }

    /**
     * Get available service types
     */
    protected function getServiceTypes(): array
    {
        return [
            'RORO' => 'Roll-on/Roll-off',
            'FCL' => 'Full Container Load',
            'LCL' => 'Less than Container Load',
            'BB' => 'Break Bulk',
            'AIR' => 'Air Freight',
        ];
    }
}

