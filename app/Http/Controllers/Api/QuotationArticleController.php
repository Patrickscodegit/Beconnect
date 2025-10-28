<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RobawsArticleCache;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class QuotationArticleController extends Controller
{
    /**
     * Get articles filtered by service type and carrier
     * Note: customer_type filter removed - it's a quotation property, not article property
     */
    public function index(Request $request): JsonResponse
    {
        \Log::info('🔍 QuotationArticleController: Fetching articles', [
            'service_type' => $request->service_type,
            'carrier_code' => $request->carrier_code,
            'has_service_type' => $request->has('service_type'),
            'has_carrier_code' => $request->has('carrier_code'),
        ]);
        
        $query = RobawsArticleCache::query();
        
        // Filter by service type if provided
        if ($request->has('service_type') && $request->service_type !== 'null' && $request->service_type !== '') {
            $serviceType = $request->service_type;
            \Log::info('📦 Filtering by service type', ['service_type' => $serviceType]);
            $query->where(function ($q) use ($serviceType) {
                $q->whereJsonContains('applicable_services', $serviceType)
                  ->orWhereNull('applicable_services');
            });
        }
        
        // Filter by carrier if provided (using shipping_line field)
        if ($request->has('carrier_code') && $request->carrier_code !== 'null' && $request->carrier_code !== '') {
            $carrierCode = $request->carrier_code;
            \Log::info('🚢 Filtering by carrier', ['carrier_code' => $carrierCode]);
            $query->where(function ($q) use ($carrierCode) {
                // Match against shipping_line (each article has one shipping line)
                $q->where('shipping_line', 'LIKE', '%' . $carrierCode . '%')
                  ->orWhereNull('shipping_line');
            });
        }
        
        // Get articles with their children
        $articles = $query->with(['children' => function ($query) {
            $query->select([
                'robaws_articles_cache.id',
                'robaws_articles_cache.robaws_article_id',
                'robaws_articles_cache.article_name',
                'robaws_articles_cache.description',
                'robaws_articles_cache.article_code',
                'robaws_articles_cache.unit_price',
                'robaws_articles_cache.unit_type',
                'robaws_articles_cache.currency',
                'robaws_articles_cache.is_parent_article',
            ]);
        }])
        ->select([
            'id',
            'robaws_article_id',
            'article_name',
            'description',
            'article_code',
            'unit_price',
            'unit_type',
            'currency',
            'category',
            'is_parent_article',
            'is_surcharge',
        ])
        ->orderBy('article_name')
        ->get();
        
        \Log::info('✅ QuotationArticleController: Returning articles', [
            'count' => $articles->count(),
            'first_few' => $articles->take(3)->pluck('description'),
        ]);
        
        return response()->json(['data' => $articles]);
    }
}

