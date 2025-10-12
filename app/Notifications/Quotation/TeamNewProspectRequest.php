<?php

namespace App\Notifications\Quotation;

use App\Models\QuotationRequest;
use App\Notifications\SafeEmailNotification;
use Illuminate\Notifications\Messages\MailMessage;

class TeamNewProspectRequest extends SafeEmailNotification
{
    public function __construct(
        private QuotationRequest $quotationRequest
    ) {}

    /**
     * Build the mail message
     */
    protected function buildMailMessage($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->getSubject())
            ->greeting('New Prospect Quotation Request')
            ->line('A new quotation request has been received from a prospect.')
            ->line('')
            ->line('**Request Number**: ' . $this->quotationRequest->request_number)
            ->line('**Prospect**: ' . ($this->quotationRequest->requester_name ?? 'Unknown'))
            ->line('**Company**: ' . ($this->quotationRequest->requester_company ?? 'N/A'))
            ->line('**Email**: ' . $this->quotationRequest->requester_email)
            ->line('**Phone**: ' . ($this->quotationRequest->requester_phone ?? 'N/A'))
            ->line('')
            ->line('**Service Type**: ' . $this->quotationRequest->service_type)
            ->line('**Trade Direction**: ' . $this->quotationRequest->trade_direction)
            ->line('**Route**: ' . $this->quotationRequest->route_display)
            ->line('**Cargo**: ' . $this->quotationRequest->cargo_summary)
            ->line('')
            ->line('**Files Uploaded**: ' . $this->quotationRequest->files->count())
            ->when($this->quotationRequest->special_requirements, function($message) {
                return $message->line('**Special Requirements**: ' . $this->quotationRequest->special_requirements);
            })
            ->action('View Request in Admin', route('filament.admin.resources.quotation-requests.view', $this->quotationRequest->id))
            ->line('Please review and process this request as soon as possible.');
    }

    /**
     * Get the email subject
     */
    protected function getSubject(): string
    {
        return 'New Prospect Quotation Request - ' . $this->quotationRequest->request_number;
    }

    /**
     * Get the array representation
     */
    public function toArray($notifiable): array
    {
        return array_merge(parent::toArray($notifiable), [
            'quotation_request_id' => $this->quotationRequest->id,
            'request_number' => $this->quotationRequest->request_number,
            'requester_email' => $this->quotationRequest->requester_email,
            'service_type' => $this->quotationRequest->service_type,
        ]);
    }
}

