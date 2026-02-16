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
                if (!empty($clientResult['success'])) {
                    $robawsProfile = $clientResult['data'] ?? null;
                }

                $offersResult = $apiClient->listOffersByClient((string) $robawsLink->robaws_client_id, 0, 10);
                if (!empty($offersResult['success'])) {
                    $robawsOffers = $offersResult['data']['items'] ?? [];
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

