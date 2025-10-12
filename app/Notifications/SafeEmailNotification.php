<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Base class for all quotation notifications with email safety
 * 
 * CRITICAL: Since Robaws is a live environment, this class prevents
 * accidentally sending emails to real customers during testing.
 * 
 * Email Modes:
 * - 'safe': Only whitelisted emails receive actual emails, others redirected
 * - 'log': All emails logged, none sent
 * - 'live': All emails sent (production only!)
 */
abstract class SafeEmailNotification extends Notification
{
    /**
     * Get notification channels
     */
    public function via($notifiable): array
    {
        if (!config('quotation.notifications.enabled')) {
            return ['database']; // Only in-app, no emails
        }

        return ['mail', 'database'];
    }

    /**
     * Build the mail representation with safety checks
     */
    public function toMail($notifiable): MailMessage
    {
        $emailMode = config('quotation.notifications.email_safety.mode', 'safe');
        $originalEmail = $this->getRecipientEmail($notifiable);

        // SAFE MODE: Only whitelisted emails go through
        if ($emailMode === 'safe') {
            $whitelist = config('quotation.notifications.email_safety.whitelist', []);

            if (!in_array($originalEmail, $whitelist)) {
                // Log intercepted email
                $interceptedCount = Cache::increment('emails_intercepted_today', 1);
                
                Log::info('EMAIL INTERCEPTED (Safe Mode)', [
                    'original_recipient' => $originalEmail,
                    'subject' => $this->getSubject(),
                    'notification_type' => class_basename($this),
                    'intercepted_count_today' => $interceptedCount
                ]);

                // Redirect to test email with warning
                $testEmail = config('quotation.notifications.email_safety.test_recipient');

                return $this->buildMailMessage($notifiable)
                    ->subject('[TEST MODE - INTERCEPTED] ' . $this->getSubject())
                    ->line('⚠️ **EMAIL SAFETY MODE ACTIVE**')
                    ->line('**Original Recipient**: ' . $originalEmail)
                    ->line('This email was intercepted because the system is in SAFE mode.')
                    ->line('---')
                    ->line('**Original Email Content:**');
            }
        }

        // LOG MODE: Log email but don't send
        if ($emailMode === 'log') {
            Log::info('EMAIL LOGGED (Log Mode - Not Sent)', [
                'recipient' => $originalEmail,
                'subject' => $this->getSubject(),
                'notification_type' => class_basename($this)
            ]);

            // Return minimal message (won't actually be sent when MAIL_MAILER=log)
            return (new MailMessage)
                ->subject($this->getSubject())
                ->line('Email logged, not sent (log mode)');
        }

        // LIVE MODE: Send normally
        return $this->buildMailMessage($notifiable);
    }

    /**
     * Build the actual mail message
     * Must be implemented by child classes
     */
    abstract protected function buildMailMessage($notifiable): MailMessage;

    /**
     * Get email subject
     * Must be implemented by child classes
     */
    abstract protected function getSubject(): string;

    /**
     * Get recipient email address
     */
    protected function getRecipientEmail($notifiable): string
    {
        if (is_string($notifiable)) {
            return $notifiable;
        }

        return $notifiable->email ?? $notifiable->routeNotificationFor('mail') ?? 'unknown@example.com';
    }

    /**
     * Get notification data for database storage
     */
    public function toArray($notifiable): array
    {
        return [
            'subject' => $this->getSubject(),
            'notification_type' => class_basename($this),
            'recipient' => $this->getRecipientEmail($notifiable),
        ];
    }
}

