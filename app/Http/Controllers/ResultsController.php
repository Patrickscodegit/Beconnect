<?php

namespace App\Http\Controllers;

use App\Models\Intake;
use App\Models\Party;
use App\Jobs\PushRobawsJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ResultsController extends Controller
{
    /**
     * Show the results page for an intake
     */
    public function show(Intake $intake)
    {
        $intake->load(['documents', 'vehicles', 'customer', 'shipper', 'consignee', 'notify']);
        
        // Get the latest extraction
        $extraction = $intake->documents()
            ->whereNotNull('extraction_json')
            ->latest()
            ->first();

        // Get status information
        $statusInfo = $this->getStatusInfo($intake);

        return view('results', compact('intake', 'extraction', 'statusInfo'));
    }

    /**
     * Assign a party role via HTMX
     */
    public function assignPartyRole(Request $request, Intake $intake)
    {
        $validator = Validator::make($request->all(), [
            'party_index' => 'required|integer',
            'party_name' => 'required|string|max:255',
            'party_address' => 'nullable|string|max:500',
            'role' => 'required|in:customer,shipper,consignee,notify'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Invalid data: ' . $validator->errors()->first()
            ], 422);
        }

        $partyName = $request->input('party_name');
        $partyAddress = $request->input('party_address');
        $role = $request->input('role');

        try {
            // Find or create the party
            $party = Party::firstOrCreate([
                'name' => $partyName,
                'address' => $partyAddress,
            ], [
                'type' => 'company', // Default type
                'contact_email' => null,
                'contact_phone' => null,
            ]);

            // Assign the party to the intake based on role
            $intake->update([
                $role . '_id' => $party->id
            ]);

            Log::info('Party role assigned', [
                'intake_id' => $intake->id,
                'party_id' => $party->id,
                'party_name' => $partyName,
                'role' => $role
            ]);

            return response('<div class="text-green-600 text-sm">✓ Assigned ' . ucfirst($role) . ': ' . $partyName . '</div>');

        } catch (\Exception $e) {
            Log::error('Failed to assign party role', [
                'intake_id' => $intake->id,
                'party_name' => $partyName,
                'role' => $role,
                'error' => $e->getMessage()
            ]);

            return response('<div class="text-red-600 text-sm">✗ Failed to assign role</div>', 500);
        }
    }

    /**
     * Push to Robaws via HTMX
     */
    public function pushRobaws(Request $request, Intake $intake)
    {
        try {
            // Check if all_verified
            $statusInfo = $this->getStatusInfo($intake);
            
            if (!($statusInfo['all_verified'] ?? false)) {
                return response('<div class="text-red-600 text-sm">✗ Cannot push: Not all data verified</div>', 422);
            }

            // Check if already pushed
            if ($intake->external_id) {
                return response('<div class="text-blue-600 text-sm">ℹ Already pushed to Robaws (ID: ' . $intake->external_id . ')</div>');
            }

            // Dispatch the push job
            PushRobawsJob::dispatch($intake)->onQueue('high');

            Log::info('Robaws push job dispatched', [
                'intake_id' => $intake->id
            ]);

            return response('<div class="text-green-600 text-sm">✓ Push to Robaws initiated...</div>');

        } catch (\Exception $e) {
            Log::error('Failed to push to Robaws', [
                'intake_id' => $intake->id,
                'error' => $e->getMessage()
            ]);

            return response('<div class="text-red-600 text-sm">✗ Failed to initiate push</div>', 500);
        }
    }

    /**
     * Get status information for an intake
     */
    private function getStatusInfo(Intake $intake): array
    {
        if ($intake->status !== 'rules_applied') {
            return ['all_verified' => false];
        }

        // Check if all vehicles are verified (have spec_id AND country_verified)
        $allVehiclesVerified = $intake->vehicles()
            ->whereNotNull('spec_id')
            ->where('country_verified', true)
            ->count() === $intake->vehicles()->count();

        // Check if required parties are assigned
        $hasRequiredParties = $intake->customer_id && $intake->shipper_id && $intake->consignee_id;

        return [
            'all_verified' => $allVehiclesVerified && $hasRequiredParties,
            'vehicles_verified' => $allVehiclesVerified,
            'parties_assigned' => $hasRequiredParties,
            'vehicle_count' => $intake->vehicles()->count(),
            'verified_vehicle_count' => $intake->vehicles()
                ->whereNotNull('spec_id')
                ->where('country_verified', true)
                ->count()
        ];
    }
}
