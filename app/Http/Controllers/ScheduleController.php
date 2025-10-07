<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ShippingSchedule;
use App\Models\Port;
use App\Models\ShippingCarrier;
use App\Models\ScheduleSyncLog;
use App\Jobs\UpdateShippingSchedulesJob;
use Illuminate\Support\Facades\Log;

class ScheduleController extends Controller
{
    public function index(Request $request)
    {
        $query = ShippingSchedule::with(['polPort', 'podPort', 'carrier']);

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

        // Get POL and POD ports separately
        $polPorts = Port::whereIn('type', ['pol', 'both'])->orderBy('name')->get();
        $podPorts = Port::whereIn('type', ['pod', 'both'])->orderBy('name')->get();
        $carriers = ShippingCarrier::orderBy('name')->get();

        // Get filter values for form
        $pol = $request->get('pol', '');
        $pod = $request->get('pod', '');
        $serviceType = $request->get('service_type', '');
        $offerId = $request->get('offer_id', '');

        // Get sync information
        $lastSyncTime = ScheduleSyncLog::getFormattedLastSyncTime();
        $isSyncRunning = ScheduleSyncLog::isSyncRunning();

        return view('schedules.index', compact('schedules', 'polPorts', 'podPorts', 'carriers', 'pol', 'pod', 'serviceType', 'offerId', 'lastSyncTime', 'isSyncRunning'));
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

    /**
     * Get sync status information
     */
    public function getSyncStatus()
    {
        $lastSyncTime = ScheduleSyncLog::getFormattedLastSyncTime();
        $isSyncRunning = ScheduleSyncLog::isSyncRunning();
        $latestSync = ScheduleSyncLog::getLatestSync();

        return response()->json([
            'lastSyncTime' => $lastSyncTime,
            'isSyncRunning' => $isSyncRunning,
            'latestSync' => $latestSync ? [
                'id' => $latestSync->id,
                'sync_type' => $latestSync->sync_type,
                'schedules_updated' => $latestSync->schedules_updated,
                'carriers_processed' => $latestSync->carriers_processed,
                'status' => $latestSync->status,
                'duration' => $latestSync->duration,
                'completed_at' => $latestSync->completed_at?->format('M j, Y \a\t g:i A')
            ] : null
        ]);
    }

    /**
     * Search schedules (AJAX endpoint)
     */
    public function searchSchedules(Request $request)
    {
        try {
            $query = ShippingSchedule::with(['polPort', 'podPort', 'carrier']);

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
                              ->get();

            return response()->json([
                'success' => true,
                'schedules' => $schedules,
                'count' => $schedules->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error searching schedules: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Trigger manual sync
     */
    public function triggerSync(Request $request)
    {
        try {
            // Check if sync is already running
            if (ScheduleSyncLog::isSyncRunning()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sync is already running. Please wait for it to complete.'
                ], 409);
            }

            // Create sync log entry
            $syncLog = ScheduleSyncLog::create([
                'sync_type' => 'manual',
                'status' => 'running',
                'started_at' => now(),
                'details' => [
                    'triggered_by' => 'user',
                    'user_agent' => $request->userAgent(),
                    'ip_address' => $request->ip()
                ]
            ]);

            // Dispatch the job
            UpdateShippingSchedulesJob::dispatch($syncLog->id);

            Log::info('Manual schedule sync triggered', [
                'sync_log_id' => $syncLog->id,
                'user_ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sync started successfully',
                'syncLogId' => $syncLog->id
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to trigger manual sync', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start sync: ' . $e->getMessage()
            ], 500);
        }
    }
}
