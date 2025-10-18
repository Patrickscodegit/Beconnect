<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Quotation\RobawsArticlesSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RobawsWebhookController extends Controller
{
    public function __construct(
        private RobawsArticlesSyncService $syncService
    ) {}
    
    public function handleArticle(Request $request)
    {
        // Step 1: Verify signature (CRITICAL for security)
        if (!$this->verifySignature($request)) {
            Log::warning('Invalid webhook signature', [
                'ip' => $request->ip(),
                'signature' => $request->header('Robaws-Signature'),
                'body' => $request->getContent()
            ]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }
        
        // Step 2: Parse event
        $event = $request->input('event'); // article.created, article.updated, article.stock-changed
        $data = $request->input('data'); // Full article data
        $webhookId = $request->input('id'); // Webhook event ID
        
        Log::info('Webhook received', [
            'event' => $event,
            'webhook_id' => $webhookId,
            'article_id' => $data['id'] ?? null
        ]);
        
        // Step 3: Process article update (synchronous)
        try {
            $this->syncService->processArticleFromWebhook($data, $event);
            
            Log::info('Webhook processed successfully', [
                'event' => $event,
                'article_id' => $data['id'] ?? null
            ]);
            
        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'event' => $event,
                'article_id' => $data['id'] ?? null,
                'error' => $e->getMessage()
            ]);
            
            // Still return 200 to prevent Robaws from retrying
            // Log the error for manual review
        }
        
        // Step 4: Return 2XX within 2 seconds (Robaws requirement)
        return response()->json(['status' => 'success'], 200);
    }
    
    private function verifySignature(Request $request): bool
    {
        $signatureHeader = $request->header('Robaws-Signature');
        
        if (!$signatureHeader) {
            return false;
        }
        
        // Get secret from database
        $config = DB::table('webhook_configurations')
            ->where('provider', 'robaws')
            ->where('is_active', true)
            ->first();
            
        if (!$config) {
            Log::error('No active webhook configuration found');
            return false;
        }
        
        $secret = $config->secret;
        
        // Parse signature header: "t=1674742714,v1=signature"
        parse_str(str_replace(',', '&', $signatureHeader), $parts);
        $timestamp = $parts['t'] ?? null;
        $receivedSignature = $parts['v1'] ?? null;
        
        if (!$timestamp || !$receivedSignature) {
            return false;
        }
        
        // Validate timestamp (reject if older than 5 minutes)
        $age = time() - (int)$timestamp;
        if ($age > 300) { // 5 minutes
            Log::warning('Webhook timestamp too old', ['age_seconds' => $age]);
            return false;
        }
        
        // Build signed payload: timestamp + '.' + body
        $payload = $timestamp . '.' . $request->getContent();
        
        // Compute expected signature
        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        
        // Constant-time comparison
        return hash_equals($expectedSignature, $receivedSignature);
    }
}