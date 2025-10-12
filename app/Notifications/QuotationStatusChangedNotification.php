<?php

namespace App\Notifications;

use App\Models\QuotationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

class QuotationStatusChangedNotification extends SafeEmailNotification
{
    use Queueable;

    protected $quotationRequest;
    protected $oldStatus;

    /**
     * Create a new notification instance.
     */
    public function __construct(QuotationRequest $quotationRequest, ?string $oldStatus = null)
    {
        $this->quotationRequest = $quotationRequest;
        $this->oldStatus = $oldStatus;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the email subject
     */
    protected function getSubject(): string
    {
        return 'Quotation Status Update: ' . $this->quotationRequest->request_number;
    }

    /**
     * Build the mail message
     */
    protected function buildMailMessage($notifiable): MailMessage
    {
        // Determine the view link based on source
        if ($this->quotationRequest->source === 'customer') {
            $viewLink = route('customer.quotations.show', $this->quotationRequest);
        } else {
            // For prospects, use public status page
            $viewLink = route('public.quotations.status') . '?number=' . $this->quotationRequest->request_number;
        }
        
        return (new MailMessage)
            ->subject($this->getSubject())
            ->view('emails.quotation-status-changed', [
                'quotation' => $this->quotationRequest,
                'oldStatus' => $this->oldStatus,
                'viewLink' => $viewLink,
            ]);
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        return [
            'quotation_id' => $this->quotationRequest->id,
            'request_number' => $this->quotationRequest->request_number,
            'old_status' => $this->oldStatus,
            'new_status' => $this->quotationRequest->status,
        ];
    }
}

