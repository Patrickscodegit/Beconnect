<?php

namespace App\Http\Controllers;

use App\Services\IntakeCreationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ApiIntakeController extends Controller
{
    public function __construct(
        private IntakeCreationService $intakeCreationService
    ) {}

    public function createFromScreenshot(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'image_data' => 'required|string',
            'filename' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'customer_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $intake = $this->intakeCreationService->createFromBase64Image(
                $request->input('image_data'),
                $request->input('filename'),
                [
                    'source' => 'screenshot_api',
                    'notes' => $request->input('notes'),
                    'priority' => $request->input('priority', 'normal'),
                    'customer_name' => $request->input('customer_name'),
                    'contact_email' => $request->input('contact_email'),
                    'contact_phone' => $request->input('contact_phone'),
                ]
            );

            return response()->json([
                'success' => true,
                'intake_id' => $intake->id,
                'status' => $intake->status,
                'message' => 'Screenshot intake created successfully'
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create screenshot intake', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to create intake',
                'message' => 'An unexpected error occurred'
            ], 500);
        }
    }

    public function createFromText(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'text_content' => 'required|string|max:10000',
            'notes' => 'nullable|string|max:1000',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'customer_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        try {
            $intake = $this->intakeCreationService->createFromText(
                $request->input('text_content'),
                [
                    'source' => 'text_api',
                    'notes' => $request->input('notes'),
                    'priority' => $request->input('priority', 'normal'),
                    'customer_name' => $request->input('customer_name'),
                    'contact_email' => $request->input('contact_email'),
                    'contact_phone' => $request->input('contact_phone'),
                ]
            );

            return response()->json([
                'success' => true,
                'intake_id' => $intake->id,
                'status' => $intake->status,
                'message' => 'Text intake created successfully'
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create text intake', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to create intake',
                'message' => 'An unexpected error occurred'
            ], 500);
        }
    }

    public function getIntakeStatus(Request $request, int $intakeId): JsonResponse
    {
        try {
            $intake = \App\Models\Intake::findOrFail($intakeId);
            
            return response()->json([
                'intake_id' => $intake->id,
                'status' => $intake->status,
                'source' => $intake->source,
                'customer_name' => $intake->customer_name,
                'contact_email' => $intake->contact_email,
                'contact_phone' => $intake->contact_phone,
                'robaws_offer_id' => $intake->robaws_offer_id,
                'robaws_offer_number' => $intake->robaws_offer_number,
                'created_at' => $intake->created_at,
                'updated_at' => $intake->updated_at,
                'extraction_data' => $intake->extraction_data ? json_decode($intake->extraction_data, true) : null,
                'last_export_error' => $intake->last_export_error,
                'last_export_error_at' => $intake->last_export_error_at,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Intake not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to get intake status', [
                'intake_id' => $intakeId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to retrieve intake status'
            ], 500);
        }
    }
}
