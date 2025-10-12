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
            ->active()
            ->upcomingSailings();

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

        return [
            'schedules' => $schedules,
            'polPorts' => $polPorts,
            'podPorts' => $podPorts,
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
     * Get POL (Port of Loading) ports
     */
    protected function getPolPorts()
    {
        return Port::active()
            ->whereIn('code', ['ANR', 'ZEE', 'FLU']) // Antwerp, Zeebrugge, Flushing
            ->orderBy('name')
            ->get();
    }

    /**
     * Get POD (Port of Discharge) ports
     */
    protected function getPodPorts()
    {
        return Port::active()
            ->whereIn('code', ['ABJ', 'CKY', 'COO', 'DKR', 'DAR', 'DLA', 'DUR', 'ELS', 'LOS', 'LFW', 'MBA', 'PNR', 'PLZ', 'WVB'])
            ->orderBy('name')
            ->get();
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

