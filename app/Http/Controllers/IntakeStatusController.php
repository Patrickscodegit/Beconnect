<?php

namespace App\Http\Controllers;

use App\Models\Intake;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;

class IntakeStatusController extends Controller
{
    public function show(Intake $intake): JsonResponse
    {
        $progressMap = [
            'uploaded' => 5,
            'preprocessed' => 20, 
            'ocr_done' => 40,
            'classified' => 55,
            'llm_extracted' => 70,
            'rules_applied' => 85,
            'posted_to_robaws' => 95,
            'done' => 100,
        ];

        $status = $intake->status ?? 'uploaded';
        $progress = $progressMap[$status] ?? 5;

        // Check if all vehicles are verified (have spec_id and country_verified)
        $vehicles = Vehicle::where('intake_id', $intake->id)
            ->get(['spec_id', 'country_verified']);
        
        $allVerified = $vehicles->count() > 0
            ? $vehicles->every(fn($v) => !empty($v->spec_id) && (bool)$v->country_verified)
            : false;

        return response()->json([
            'status' => $status,
            'progress' => $progress,
            'all_verified' => $allVerified,
            'vehicle_count' => $vehicles->count(),
            'intake_id' => $intake->id,
            'notes' => $intake->notes ?? [],
        ]);
    }
}
