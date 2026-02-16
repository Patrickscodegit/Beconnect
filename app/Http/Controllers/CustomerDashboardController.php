<?php

namespace App\Http\Controllers;

use App\Models\QuotationRequest;
use App\Services\Export\Clients\RobawsApiClient;
use App\Services\Robaws\RobawsPortalLinkResolver;
use Illuminate\Http\Request;

class CustomerDashboardController extends Controller
{
    /**
     * Show customer dashboard
     */
    public function index()
    {
        $user = auth()->user();
        $robawsLink = null;
        $robawsProfile = null;
        $robawsOffers = [];
        
        // Get customer's quotations
        $recentQuotations = QuotationRequest::where(function($q) use ($user) {
                $q->where('contact_email', $user->email)
                  ->orWhere('client_email', $user->email);
            })
            ->latest()
            ->limit(5)
            ->get();
            
        // Calculate stats
        $stats = [
            'total' => QuotationRequest::where('contact_email', $user->email)
                ->orWhere('client_email', $user->email)
                ->count(),
            'pending' => QuotationRequest::where(function($q) use ($user) {
                    $q->where('contact_email', $user->email)
                      ->orWhere('client_email', $user->email);
                })
                ->where('status', 'pending')
                ->count(),
            'processing' => QuotationRequest::where(function($q) use ($user) {
                    $q->where('contact_email', $user->email)
                      ->orWhere('client_email', $user->email);
                })
                ->where('status', 'processing')
                ->count(),
            'quoted' => QuotationRequest::where(function($q) use ($user) {
                    $q->where('contact_email', $user->email)
                      ->orWhere('client_email', $user->email);
                })
                ->where('status', 'quoted')
                ->count(),
            'accepted' => QuotationRequest::where(function($q) use ($user) {
                    $q->where('contact_email', $user->email)
                      ->orWhere('client_email', $user->email);
                })
                ->where('status', 'accepted')
                ->count(),
        ];

        try {
            $robawsLink = app(RobawsPortalLinkResolver::class)->resolveForUser($user);
            if ($robawsLink) {
                $apiClient = app(RobawsApiClient::class);

                $clientResult = $apiClient->getClientById((string) $robawsLink->robaws_client_id, ['contacts']);
                if (is_array($clientResult)) {
                    if (!empty($clientResult['success'])) {
                        $robawsProfile = $clientResult['data'] ?? null;
                    } else {
                        $robawsProfile = $clientResult;
                    }
                }

                $offersResult = $apiClient->listOffersByClient((string) $robawsLink->robaws_client_id, 0, 10);
                if (!empty($offersResult['success'])) {
                    $robawsOffers = $offersResult['data']['items'] ?? [];

                    if (!empty($robawsOffers)) {
                        $offersCollection = collect($robawsOffers);
                        $offerIds = $offersCollection->pluck('id')->filter()->unique()->values();
                        $offerNumbers = $offersCollection
                            ->map(fn ($offer) => $apiClient->getOfferDisplayNumber($offer))
                            ->filter()
                            ->unique()
                            ->values();

                        $quotationQuery = QuotationRequest::query();
                        if ($offerIds->isNotEmpty() || $offerNumbers->isNotEmpty()) {
                            $quotationQuery->where(function ($q) use ($offerIds, $offerNumbers) {
                                if ($offerIds->isNotEmpty()) {
                                    $q->whereIn('robaws_offer_id', $offerIds);
                                }
                                if ($offerNumbers->isNotEmpty()) {
                                    $method = $offerIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                                    $q->{$method}('robaws_offer_number', $offerNumbers);
                                }
                            });
                        }

                        $quotations = $quotationQuery
                            ->get(['robaws_offer_id', 'robaws_offer_number', 'request_number']);
                        $quotationsById = $quotations->keyBy('robaws_offer_id');
                        $quotationsByNumber = $quotations->keyBy('robaws_offer_number');

                        $robawsOffers = $offersCollection
                            ->map(function ($offer) use ($apiClient, $quotationsById, $quotationsByNumber) {
                                $offerId = $offer['id'] ?? null;
                                $displayNumber = $apiClient->getOfferDisplayNumber($offer);

                                $quotation = null;
                                if ($offerId && $quotationsById->has($offerId)) {
                                    $quotation = $quotationsById->get($offerId);
                                } elseif ($displayNumber && $quotationsByNumber->has($displayNumber)) {
                                    $quotation = $quotationsByNumber->get($displayNumber);
                                }

                                $offer['display_number'] = $displayNumber;
                                $bconnectNumber = $quotation?->request_number
                                    ?? $apiClient->getOfferBconnectRequestNumber($offer);
                                if ($bconnectNumber) {
                                    $offer['bconnect_request_number'] = $bconnectNumber;
                                }

                                return $offer;
                            })
                            ->values()
                            ->all();
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Failed to load Robaws portal data', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
        
        return view('customer.dashboard', compact('recentQuotations', 'stats', 'user', 'robawsLink', 'robawsProfile', 'robawsOffers'));
    }
}

