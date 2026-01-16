<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShippingSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScheduleSearchController extends Controller
{
    /**
     * Search for shipping schedules based on POL, POD, and optional service type
     */
    public function search(Request $request)
    {
        $pol = $request->input('pol');
        $pod = $request->input('pod');
        $serviceType = $request->input('service_type');

        // Validate required parameters
        if (!$pol || !$pod) {
            return response()->json([
                'success' => false,
                'message' => 'Both POL and POD are required',
                'schedules' => []
            ], 400);
        }

        try {
            // Query schedules with POL/POD filtering
            $query = ShippingSchedule::active()
                ->whereHas('polPort', function($q) use ($pol) {
                    $q->where('name', 'like', "%{$pol}%")
                      ->orWhere('code', 'like', "%{$pol}%");
                })
                ->whereHas('podPort', function($q) use ($pod) {
                    $q->where('name', 'like', "%{$pod}%")
                      ->orWhere('code', 'like', "%{$pod}%");
                })
                ->where(function($q) {
                    $q->whereDate('next_sailing_date', '>=', today())
                      ->orWhereNull('next_sailing_date'); // Include TBA dates
                })
                ->with(['carrier', 'polPort', 'podPort'])
                ->orderBy('next_sailing_date', 'asc');

            // Optional service type filter
            // Note: Not applying strict filter due to potential format mismatches
            // between config (e.g., "RORO_EXPORT") and database (e.g., "RORO")
            
            $schedules = $query->get()->map(function($schedule) {
                return [
                    'id' => $schedule->id,
                    'label' => sprintf(
                        '%s → %s | Departs: %s | Transit: %d days',
                        $schedule->polPort->name ?? 'Unknown POL',
                        $schedule->podPort->name ?? 'Unknown POD',
                        $schedule->next_sailing_date?->format('M d, Y') ?? 'TBA',
                        $schedule->transit_days ?? 0
                    ),
                    'carrier' => $schedule->carrier->name ?? 'Unknown', // For admin use
                    'hide_carrier' => true, // Flag to hide carrier from customers
                    'carrier_code' => $schedule->carrier->code ?? null,
                    'pol' => $schedule->polPort->name ?? 'Unknown',
                    'pol_code' => $schedule->polPort->code ?? null,
                    'pod' => $schedule->podPort->name ?? 'Unknown',
                    'pod_code' => $schedule->podPort->code ?? null,
                    'departure_date' => $schedule->next_sailing_date?->format('Y-m-d'),
                    'transit_days' => $schedule->transit_days ?? 0,
                    'service_name' => $schedule->service_name ?? 'N/A',
                    'frequency' => $schedule->frequency ?? 'N/A',
                ];
            });

            Log::info('Schedule search completed', [
                'pol' => $pol,
                'pod' => $pod,
                'service_type' => $serviceType,
                'results_count' => $schedules->count()
            ]);

            return response()->json([
                'success' => true,
                'message' => $schedules->count() > 0 
                    ? "Found {$schedules->count()} schedule(s)"
                    : 'No schedules found for this route',
                'schedules' => $schedules,
                'count' => $schedules->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Schedule search failed', [
                'error' => $e->getMessage(),
                'pol' => $pol,
                'pod' => $pod,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while searching for schedules',
                'schedules' => [],
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}

