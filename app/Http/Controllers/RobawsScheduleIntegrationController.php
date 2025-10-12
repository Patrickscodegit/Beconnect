<?php

namespace App\Http\Controllers;

use App\Models\ShippingSchedule;
use App\Models\ScheduleOfferLink;
use App\Services\Robaws\ArticleSelectionService;
use App\Services\Export\Clients\RobawsApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RobawsScheduleIntegrationController extends Controller
{
    public function __construct(
        private ArticleSelectionService $articleSelection,
        private RobawsApiClient $robawsClient
    ) {}

    /**
     * Get suggested articles for a schedule and offer
     * Reads schedule (no modifications)
     */
    public function getSuggestedArticles(Request $request)
    {
        $request->validate([
            'schedule_id' => 'required|exists:shipping_schedules,id',
            'offer_id' => 'required|string',
            'service_type' => 'required|string',
            'cargo_details' => 'array'
        ]);

        try {
            // Read schedule (read-only - no modifications)
            $schedule = ShippingSchedule::with('carrier', 'polPort', 'podPort')
                ->findOrFail($request->schedule_id);

            // Get cargo details
            $cargoDetails = $request->cargo_details ?? [];

            // Suggest articles
            $suggestions = $this->articleSelection->suggestArticlesForOffer(
                $schedule,
                $cargoDetails,
                $request->service_type
            );

            return response()->json([
                'success' => true,
                'schedule' => [
                    'id' => $schedule->id,
                    'service_name' => $schedule->service_name,
                    'carrier' => $schedule->carrier->name ?? 'Unknown',
                    'carrier_code' => $schedule->carrier->code ?? null,
                ],
                'suggestions' => $suggestions,
                'total_articles' => count($suggestions['mandatory']) + count($suggestions['recommended']) + count($suggestions['optional'])
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to suggest articles', [
                'schedule_id' => $request->schedule_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update Robaws offer with selected articles
     * Uses PATCH (Robaws best practice) and idempotency
     */
    public function updateOfferArticles(Request $request)
    {
        $request->validate([
            'offer_id' => 'required|string',
            'schedule_id' => 'required|exists:shipping_schedules,id',
            'article_ids' => 'required|array',
            'article_ids.*' => 'required|string'
        ]);

        try {
            $offerId = $request->offer_id;
            $articleIds = $request->article_ids;
            $scheduleId = $request->schedule_id;

            // Get schedule details to update offer
            $schedule = ShippingSchedule::with('carrier', 'polPort', 'podPort')
                ->findOrFail($scheduleId);

            // Create idempotency key (Robaws best practice)
            $idempotencyKey = "offer_articles_{$offerId}_" . md5(json_encode($articleIds));

            // Prepare line items from article IDs
            $lineItems = array_map(function($articleId) {
                return [
                    'articleId' => (int) $articleId,
                    'quantity' => 1
                ];
            }, $articleIds);

            // Prepare extra fields with schedule info
            $extraFields = [
                'VESSEL' => ['stringValue' => $schedule->vessel_name ?? ''],
                'VOYAGE' => ['stringValue' => $schedule->voyage_number ?? ''],
                'ETS' => ['dateValue' => $schedule->ets_pol?->format('Y-m-d') ?? null],
                'ETA' => ['dateValue' => $schedule->eta_pod?->format('Y-m-d') ?? null],
                'TRANSIT_TIME' => ['stringValue' => ($schedule->transit_days ?? '') . ' days'],
                'POL' => ['stringValue' => $schedule->polPort->name ?? ''],
                'POD' => ['stringValue' => $schedule->podPort->name ?? ''],
            ];

            // Update offer in Robaws using PATCH (not PUT)
            $response = $this->robawsClient->getHttpClient()
                ->withHeader('Idempotency-Key', $idempotencyKey)
                ->patch("/api/v2/offers/{$offerId}", [
                    'lineItems' => $lineItems,
                    'extraFields' => array_filter($extraFields, fn($v) => $v !== null)
                ]);

            if (!$response->successful()) {
                throw new \Exception('Failed to update Robaws offer: ' . $response->body());
            }

            // Store link in schedule_offer_links table (doesn't modify shipping_schedules)
            ScheduleOfferLink::updateOrCreate(
                [
                    'shipping_schedule_id' => $scheduleId,
                    'robaws_offer_id' => $offerId
                ],
                [
                    'selected_articles' => $articleIds,
                    'linked_by' => auth()->id(),
                    'linked_at' => now()
                ]
            );

            Log::info('Robaws offer updated with articles and schedule', [
                'offer_id' => $offerId,
                'schedule_id' => $scheduleId,
                'article_count' => count($articleIds)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Offer updated successfully in Robaws',
                'offer_id' => $offerId,
                'articles_added' => count($articleIds)
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update offer articles', [
                'offer_id' => $request->offer_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get integration widget HTML (for AJAX loading)
     */
    public function getWidget()
    {
        if (!auth()->check() || !auth()->user()->is_team_member) {
            return response('', 403);
        }

        return view('schedules._robaws_integration');
    }
}

