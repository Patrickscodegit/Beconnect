<?php

namespace App\Console\Commands;

use App\Models\RobawsWebhookLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class CheckWebhookHealth extends Command
{
    protected $signature = 'robaws:check-webhook-health 
                            {--alert : Send alert if issues detected}';
    
    protected $description = 'Check webhook health and optionally send alerts';

    public function handle(): int
    {
        $this->info('🔍 Checking webhook health...');
        $this->newLine();
        
        $issues = [];
        
        // Check 1: No webhooks in last 24 hours
        $last24h = RobawsWebhookLog::where('created_at', '>=', now()->subDay())->count();
        
        if ($last24h === 0) {
            $lastWebhook = RobawsWebhookLog::latest('created_at')->first();
            
            if ($lastWebhook) {
                $hoursAgo = $lastWebhook->created_at->diffInHours(now());
                $this->warn("⚠️  No webhooks received in last 24 hours");
                $this->line("   Last webhook: {$hoursAgo} hours ago");
                
                if ($hoursAgo > 48) {
                    $issues[] = "No webhooks received in {$hoursAgo} hours (last: {$lastWebhook->created_at->format('Y-m-d H:i:s')})";
                }
            } else {
                $this->warn("⚠️  No webhooks ever received");
                $issues[] = "No webhooks ever received - webhook may not be registered with Robaws";
            }
        } else {
            $this->info("✅ Received {$last24h} webhooks in last 24 hours");
        }
        
        // Check 2: Failed webhooks
        $failedLast24h = RobawsWebhookLog::where('status', 'failed')
            ->where('created_at', '>=', now()->subDay())
            ->count();
        
        if ($failedLast24h > 0) {
            $failureRate = round(($failedLast24h / max($last24h, 1)) * 100, 1);
            $this->error("❌ {$failedLast24h} failed webhooks in last 24 hours ({$failureRate}% failure rate)");
            
            if ($failureRate > 5) { // Alert if >5% failure rate
                $issues[] = "{$failedLast24h} webhooks failed in last 24 hours ({$failureRate}% failure rate)";
            }
            
            // Show recent failures
            $recentFailures = RobawsWebhookLog::where('status', 'failed')
                ->where('created_at', '>=', now()->subDay())
                ->latest()
                ->limit(5)
                ->get();
            
            $this->newLine();
            $this->line('Recent failures:');
            foreach ($recentFailures as $failure) {
                $this->line("  - [{$failure->event_type}] {$failure->error_message}");
            }
        } else {
            $this->info("✅ No failed webhooks in last 24 hours");
        }
        
        // Check 3: Stuck in processing
        $stuckProcessing = RobawsWebhookLog::where('status', 'processing')
            ->where('created_at', '<', now()->subHour())
            ->count();
        
        if ($stuckProcessing > 0) {
            $this->warn("⚠️  {$stuckProcessing} webhooks stuck in 'processing' status");
            $issues[] = "{$stuckProcessing} webhooks stuck in processing status (may indicate deadlocks or crashes)";
        }
        
        // Check 4: Success rate
        $totalProcessed = RobawsWebhookLog::where('created_at', '>=', now()->subWeek())->count();
        $successfulProcessed = RobawsWebhookLog::where('status', 'processed')
            ->where('created_at', '>=', now()->subWeek())
            ->count();
        
        if ($totalProcessed > 0) {
            $successRate = round(($successfulProcessed / $totalProcessed) * 100, 1);
            
            $this->newLine();
            $this->line("📊 7-day success rate: {$successRate}%");
            
            if ($successRate < 95) {
                $this->warn("⚠️  Success rate below 95%");
                if ($successRate < 90) {
                    $issues[] = "Low success rate: {$successRate}% (last 7 days)";
                }
            }
        }
        
        $this->newLine();
        
        // Summary
        if (empty($issues)) {
            $this->info('✅ All webhook health checks passed!');
            return 0;
        } else {
            $this->error('❌ Webhook health issues detected:');
            foreach ($issues as $issue) {
                $this->line("  • {$issue}");
            }
            
            // Send alert if requested
            if ($this->option('alert')) {
                $this->sendAlert($issues);
            }
            
            return 1;
        }
    }
    
    private function sendAlert(array $issues): void
    {
        $this->newLine();
        $this->info('📧 Sending alert...');
        
        $message = "Robaws Webhook Health Alert\n\n";
        $message .= "Issues detected:\n";
        foreach ($issues as $issue) {
            $message .= "• {$issue}\n";
        }
        
        // Log to Laravel log
        Log::warning('Webhook health issues detected', [
            'issues' => $issues,
            'timestamp' => now()->toIso8601String()
        ]);
        
        // TODO: Add Slack notification
        // Notification::route('slack', config('logging.channels.slack.url'))
        //     ->notify(new \App\Notifications\WebhookHealthAlert($issues));
        
        // TODO: Add email notification
        // Mail::to(config('quotation.notifications.team_email'))
        //     ->send(new \App\Mail\WebhookHealthAlert($issues));
        
        $this->line('Alert logged to Laravel log');
        $this->comment('TODO: Configure Slack/Email notifications in code');
    }
}

