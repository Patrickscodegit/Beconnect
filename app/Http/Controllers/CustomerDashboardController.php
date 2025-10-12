<?php

namespace App\Http\Controllers;

use App\Models\QuotationRequest;
use Illuminate\Http\Request;

class CustomerDashboardController extends Controller
{
    /**
     * Show customer dashboard
     */
    public function index()
    {
        $user = auth()->user();
        
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
        
        return view('customer.dashboard', compact('recentQuotations', 'stats', 'user'));
    }
}

