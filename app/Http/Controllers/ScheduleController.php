<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ShippingSchedule;
use App\Models\Port;
use App\Models\ShippingCarrier;

class ScheduleController extends Controller
{
    public function index(Request $request)
    {
        $query = ShippingSchedule::with(['pol', 'pod', 'carrier']);

        // Apply filters
        if ($request->filled('pol')) {
            $query->where('pol_code', $request->pol);
        }

        if ($request->filled('pod')) {
            $query->where('pod_code', $request->pod);
        }

        if ($request->filled('carrier')) {
            $query->where('carrier_code', $request->carrier);
        }

        if ($request->filled('service_type')) {
            $query->where('service_type', $request->service_type);
        }

        $schedules = $query->orderBy('pol_code')
                          ->orderBy('pod_code')
                          ->orderBy('carrier_name')
                          ->paginate(50);

        $ports = Port::orderBy('name')->get();
        $carriers = ShippingCarrier::orderBy('name')->get();

        // Get filter values for form
        $pol = $request->get('pol', '');
        $pod = $request->get('pod', '');
        $serviceType = $request->get('service_type', '');
        $offerId = $request->get('offer_id', '');

        return view('schedules.index', compact('schedules', 'ports', 'carriers', 'pol', 'pod', 'serviceType', 'offerId'));
    }

    public function updateOffer(Request $request)
    {
        $request->validate([
            'offer_id' => 'required|string',
            'schedule_id' => 'required|integer|exists:shipping_schedules,id'
        ]);

        try {
            $schedule = ShippingSchedule::findOrFail($request->schedule_id);
            
            // Here you would integrate with Robaws API to update the offer
            // For now, just return success
            
            return response()->json([
                'success' => true,
                'message' => 'Schedule updated successfully',
                'schedule' => $schedule
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update schedule: ' . $e->getMessage()
            ], 500);
        }
    }
}
