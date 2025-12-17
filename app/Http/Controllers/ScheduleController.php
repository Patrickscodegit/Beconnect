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
            $query->whereHas('carrier', function($q) use ($request) {
                $q->where('code', $request->carrier);
            });
        }

        if ($request->filled('service_type')) {
            // Normalize service_type to uppercase (database stores RORO, FCL, etc. in uppercase)
            $serviceType = strtoupper($request->service_type);
            
            // Get all carriers and filter by service_type in PHP (whereJsonContains doesn't work reliably)
            $allCarriers = \App\Models\ShippingCarrier::all();
            $matchingCarrierIds = [];
            
            foreach ($allCarriers as $carrier) {
                // Ensure service_types is an array (handle both array and JSON string)
                $serviceTypes = $carrier->service_types;
                if (is_string($serviceTypes)) {
                    $serviceTypes = json_decode($serviceTypes, true) ?? [];
                }
                if (!is_array($serviceTypes)) {
                    $serviceTypes = [];
                }
                
                // Check if this carrier has the requested service type
                if (in_array($serviceType, $serviceTypes)) {
                    $matchingCarrierIds[] = $carrier->id;
                }
            }
            
            // Filter schedules by matching carrier IDs
            if (!empty($matchingCarrierIds)) {
                $query->whereIn('carrier_id', $matchingCarrierIds);
            } else {
                // No matching carriers, return empty result
                $query->whereRaw('1 = 0'); // Force no results
            }
        }

        $schedules = $query->orderBy('vessel_name')
                          ->orderBy('ets_pol')
                          ->paginate(50);

        // Get POL and POD ports separately
        // Define port roles for flexibility (ports can be both POL and POD)
        $polPortCodes = ['ANR', 'ZEE', 'FLU']; // Current POL ports
        $podPortCodes = ['ABJ', 'CKY', 'COO', 'DKR', 'DAR', 'DLA', 'DUR', 'ELS', 'LOS', 'LFW', 'MBA', 'PNR', 'PLZ', 'WVB']; // Current POD ports
        
        // Future-ready: To add Antwerp as POD, simply add 'ANR' to $podPortCodes array
        // Example: $podPortCodes = ['ABJ', 'CKY', 'COO', 'DKR', 'DAR', 'DLA', 'DUR', 'ELS', 'LOS', 'LFW', 'MBA', 'PNR', 'PLZ', 'WVB', 'ANR'];
        
        $polPorts = Port::whereIn('code', $polPortCodes)->orderBy('name')->get();
        $podPorts = Port::whereIn('code', $podPortCodes)->orderBy('name')->get();
        $carriers = ShippingCarrier::orderBy('name')->get();

        // Get filter values for form
        $pol = $request->get('pol', '');
        $pod = $request->get('pod', '');
        $serviceType = $request->get('service_type', '');
        $offerId = $request->get('offer_id', '');

        // Get sync information
        $lastSyncTime = \Schema::hasTable('schedule_sync_logs') ? ScheduleSyncLog::getFormattedLastSyncTime() : 'Database not ready';
        $isSyncRunning = \Schema::hasTable('schedule_sync_logs') ? ScheduleSyncLog::isSyncRunning() : false;

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
        try {
            // Check if the schedule_sync_logs table exists
            if (!\Schema::hasTable('schedule_sync_logs')) {
                return response()->json([
                    'lastSyncTime' => 'Database not ready',
                    'isSyncRunning' => false,
                    'latestSync' => null,
                    'error' => 'Schedule sync logs table not found. Please run migrations.'
                ]);
            }

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
        } catch (\Exception $e) {
            Log::error('Error getting sync status', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'lastSyncTime' => 'Error',
                'isSyncRunning' => false,
                'latestSync' => null,
                'error' => 'Failed to get sync status: ' . $e->getMessage()
            ], 500);
        }
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
                $query->whereHas('carrier', function($q) use ($request) {
                    $q->where('code', $request->carrier);
                });
            }

            if ($request->filled('service_type')) {
                // Normalize service_type to uppercase (database stores RORO, FCL, etc. in uppercase)
                $serviceType = strtoupper($request->service_type);
                
                // Get all carriers and filter by service_type in PHP (whereJsonContains doesn't work reliably)
                $allCarriers = \App\Models\ShippingCarrier::all();
                $matchingCarrierIds = [];
                
                foreach ($allCarriers as $carrier) {
                    // Ensure service_types is an array (handle both array and JSON string)
                    $serviceTypes = $carrier->service_types;
                    if (is_string($serviceTypes)) {
                        $serviceTypes = json_decode($serviceTypes, true) ?? [];
                    }
                    if (!is_array($serviceTypes)) {
                        $serviceTypes = [];
                    }
                    
                    // Check if this carrier has the requested service type
                    if (in_array($serviceType, $serviceTypes)) {
                        $matchingCarrierIds[] = $carrier->id;
                    }
                }
                
                // Filter schedules by matching carrier IDs
                if (!empty($matchingCarrierIds)) {
                    $query->whereIn('carrier_id', $matchingCarrierIds);
                } else {
                    // No matching carriers, return empty result
                    $query->whereRaw('1 = 0'); // Force no results
                }
            }

            $schedules = $query->orderBy('ets_pol', 'asc')
                              ->get();

            // Group schedules by carrier for the frontend
            $carriers = [];
            foreach ($schedules as $schedule) {
                $carrierCode = $schedule->carrier->code;
                if (!isset($carriers[$carrierCode])) {
                    $carriers[$carrierCode] = [
                        'name' => $schedule->carrier->name,
                        'code' => $schedule->carrier->code,
                        'specialization' => $schedule->carrier->specialization,
                        'service_types' => $schedule->carrier->service_types,
                        'schedules' => []
                    ];
                }
                // Add dynamic frequency display to schedule
                $schedule->accurate_frequency_display = $schedule->accurate_frequency_display;
                $carriers[$carrierCode]['schedules'][] = $schedule;
            }

            return response()->json([
                'success' => true,
                'carriers' => $carriers,
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
     * Reset stuck sync operations
     */
    public function resetStuckSync()
    {
        try {
            // Check if the schedule_sync_logs table exists
            if (!\Schema::hasTable('schedule_sync_logs')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Schedule sync logs table not found.'
                ], 500);
            }

            // Find and reset stuck syncs (running but started more than 30 minutes ago)
            $stuckSyncs = ScheduleSyncLog::whereNull('completed_at')
                ->where('started_at', '<', now()->subMinutes(30))
                ->get();

            $resetCount = 0;
            foreach ($stuckSyncs as $sync) {
                $sync->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'error_message' => 'Reset by user - sync was stuck for more than 30 minutes',
                    'details' => array_merge($sync->details ?? [], [
                        'reset_at' => now()->toISOString(),
                        'reset_reason' => 'user_reset'
                    ])
                ]);
                $resetCount++;
            }

            Log::info('Stuck sync operations reset by user', [
                'reset_count' => $resetCount,
                'user_ip' => request()->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => "Reset {$resetCount} stuck sync operation(s).",
                'reset_count' => $resetCount
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to reset stuck sync', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reset stuck sync: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Trigger manual sync
     */
    public function triggerSync(Request $request)
    {
        try {
            // Check if the schedule_sync_logs table exists
            if (!\Schema::hasTable('schedule_sync_logs')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Schedule sync logs table not found. Please run migrations first.'
                ], 500);
            }

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

            // Temporary: Run synchronously until Horizon is configured
            // UpdateShippingSchedulesJob::dispatch($syncLog->id);
            UpdateShippingSchedulesJob::dispatchSync($syncLog->id);

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
