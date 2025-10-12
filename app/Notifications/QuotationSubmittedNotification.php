<?php

namespace App\Notifications;

use App\Models\QuotationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

class QuotationSubmittedNotification extends SafeEmailNotification
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
        return 'New Quotation Request: ' . $this->quotationRequest->request_number;
    }

    /**
     * Build the mail message
     */
    protected function buildMailMessage($notifiable): MailMessage
    {
        $serviceType = config('quotation.service_types.' . $this->quotationRequest->service_type);
        $serviceName = is_array($serviceType) ? $serviceType['name'] : ($serviceType ?: $this->quotationRequest->service_type);
        
        $adminLink = route('filament.admin.resources.quotation-requests.view', $this->quotationRequest);
        
        return (new MailMessage)
            ->subject($this->getSubject())
            ->view('emails.quotation-submitted', [
                'quotation' => $this->quotationRequest,
                'serviceType' => $serviceName,
                'adminLink' => $adminLink,
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
            'contact_name' => $this->quotationRequest->contact_name,
            'route' => $this->quotationRequest->pol . ' → ' . $this->quotationRequest->pod,
        ];
    }
}

