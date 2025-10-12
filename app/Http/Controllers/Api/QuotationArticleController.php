<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RobawsArticleCache;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class QuotationArticleController extends Controller
{
    /**
     * Get articles filtered by service type and customer type
     */
    public function index(Request $request): JsonResponse
    {
        $query = RobawsArticleCache::query();
        
        // Filter by service type if provided
        if ($request->has('service_type') && $request->service_type !== 'null' && $request->service_type !== '') {
            $serviceType = $request->service_type;
            $query->where(function ($q) use ($serviceType) {
                $q->whereJsonContains('applicable_services', $serviceType)
                  ->orWhereNull('applicable_services')
                  ->orWhereRaw('JSON_LENGTH(applicable_services) = 0');
            });
        }
        
        // Filter by customer type if provided
        if ($request->has('customer_type') && $request->customer_type !== 'null' && $request->customer_type !== '') {
            $customerType = $request->customer_type;
            $query->where(function ($q) use ($customerType) {
                $q->where('customer_type', $customerType)
                  ->orWhereNull('customer_type');
            });
        }
        
        // Filter by carrier if provided
        if ($request->has('carrier_code') && $request->carrier_code !== 'null' && $request->carrier_code !== '') {
            $carrierCode = $request->carrier_code;
            $query->where(function ($q) use ($carrierCode) {
                $q->whereJsonContains('applicable_carriers', $carrierCode)
                  ->orWhereNull('applicable_carriers')
                  ->orWhere('applicable_carriers', '[]')
                  ->orWhere('applicable_carriers', '');
            });
        }
        
        // Get articles with their children
        $articles = $query->with(['children' => function ($query) {
            $query->select([
                'robaws_articles_cache.id',
                'robaws_articles_cache.robaws_article_id',
                'robaws_articles_cache.description',
                'robaws_articles_cache.article_code',
                'robaws_articles_cache.unit_price',
                'robaws_articles_cache.unit_type',
                'robaws_articles_cache.is_parent_article',
            ]);
        }])
        ->select([
            'id',
            'robaws_article_id',
            'description',
            'article_code',
            'unit_price',
            'unit_type',
            'category',
            'is_parent_article',
            'is_surcharge',
        ])
        ->orderBy('description')
        ->get();
        
        return response()->json($articles);
    }
}

