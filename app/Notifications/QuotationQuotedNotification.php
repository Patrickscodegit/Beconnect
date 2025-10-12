<?php

namespace App\Notifications;

use App\Models\QuotationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

class QuotationQuotedNotification extends SafeEmailNotification
{
    use Queueable;

    protected $quotationRequest;

    /**
     * Create a new notification instance.
     */
    public function __construct(QuotationRequest $quotationRequest)
    {
        $this->quotationRequest = $quotationRequest;
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
        return 'Your Quotation is Ready: ' . $this->quotationRequest->request_number;
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
        
        // Accept link (could be a dedicated accept page or same as view link)
        $acceptLink = $viewLink;
        
        return (new MailMessage)
            ->subject($this->getSubject())
            ->view('emails.quotation-quoted', [
                'quotation' => $this->quotationRequest,
                'viewLink' => $viewLink,
                'acceptLink' => $acceptLink,
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
            'total_incl_vat' => $this->quotationRequest->total_incl_vat,
            'expires_at' => $this->quotationRequest->expires_at,
        ];
    }
}

