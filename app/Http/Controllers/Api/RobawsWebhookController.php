<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RobawsWebhookLog;
use App\Services\Quotation\RobawsArticlesSyncService;
use App\Services\Robaws\RobawsCustomerSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RobawsWebhookController extends Controller
{
    public function __construct(
        private RobawsArticlesSyncService $syncService,
        private RobawsCustomerSyncService $customerSyncService
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
        
        // Create webhook log entry
        $webhookLog = RobawsWebhookLog::create([
            'event_type' => $event,
            'robaws_id' => $webhookId,
            'article_id' => null, // Will be updated after processing
            'payload' => $request->all(),
            'status' => 'received',
        ]);
        
        // Step 3: Process article update (synchronous)
        $startTime = microtime(true);
        
        try {
            $this->syncService->processArticleFromWebhook($data, $event);
            
            // Calculate processing duration
            $duration = (int) ((microtime(true) - $startTime) * 1000); // ms
            
            // Find article to link
            $articleId = null;
            if (isset($data['id'])) {
                $article = \App\Models\RobawsArticleCache::where('robaws_article_id', $data['id'])->first();
                if ($article) {
                    $articleId = $article->id;
                }
            }
            
            // Mark as processed
            $webhookLog->update([
                'status' => 'processed',
                'processed_at' => now(),
                'processing_duration_ms' => $duration,
                'article_id' => $articleId,
            ]);
            
            Log::info('Webhook processed successfully', [
                'event' => $event,
                'article_id' => $data['id'] ?? null
            ]);
            
        } catch (\Exception $e) {
            // Mark as failed
            $webhookLog->markAsFailed($e->getMessage());
            
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
    
    public function handleCustomer(Request $request)
    {
        // Step 1: Verify signature (CRITICAL for security)
        if (!$this->verifySignature($request, 'robaws_customers')) {
            Log::warning('Invalid customer webhook signature', [
                'ip' => $request->ip(),
                'signature' => $request->header('Robaws-Signature'),
                'body' => $request->getContent()
            ]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }
        
        // Step 2: Parse event
        $event = $request->input('event'); // client.created, client.updated
        $data = $request->input('data'); // Full customer data
        $webhookId = $request->input('id'); // Webhook event ID
        
        Log::info('Customer webhook received', [
            'event' => $event,
            'webhook_id' => $webhookId,
            'client_id' => $data['id'] ?? null
        ]);
        
        // Create webhook log entry
        $webhookLog = RobawsWebhookLog::create([
            'event_type' => $event,
            'robaws_id' => $webhookId,
            'article_id' => null, // Not applicable for customers
            'payload' => $request->all(),
            'status' => 'received',
        ]);
        
        // Step 3: Process customer update (synchronous)
        $startTime = microtime(true);
        
        try {
            $customer = $this->customerSyncService->processCustomerFromWebhook($data);
            
            // Calculate processing duration
            $duration = (int) ((microtime(true) - $startTime) * 1000); // ms
            
            // Mark as processed
            $webhookLog->update([
                'status' => 'processed',
                'processed_at' => now(),
                'processing_duration_ms' => $duration,
            ]);
            
            Log::info('Customer webhook processed successfully', [
                'event' => $event,
                'client_id' => $data['id'] ?? null,
                'customer_name' => $customer->name,
                'customer_role' => $customer->role
            ]);
            
        } catch (\Exception $e) {
            // Mark as failed
            $webhookLog->markAsFailed($e->getMessage());
            
            Log::error('Customer webhook processing failed', [
                'event' => $event,
                'client_id' => $data['id'] ?? null,
                'error' => $e->getMessage()
            ]);
            
            // Still return 200 to prevent Robaws from retrying
            // Log the error for manual review
        }
        
        // Step 4: Return 2XX within 2 seconds (Robaws requirement)
        return response()->json(['status' => 'success'], 200);
    }
    
    private function verifySignature(Request $request, string $provider = 'robaws'): bool
    {
        $signatureHeader = $request->header('Robaws-Signature');
        
        if (!$signatureHeader) {
            return false;
        }
        
        // Get secret from database
        $config = DB::table('webhook_configurations')
            ->where('provider', $provider)
            ->where('is_active', true)
            ->first();
            
        if (!$config) {
            Log::error('No active webhook configuration found', ['provider' => $provider]);
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