<?php

namespace App\Services;

use App\Models\OfferTemplate;
use App\Models\QuotationRequest;
use Illuminate\Support\Facades\Log;

class OfferTemplateService
{
    /**
     * Extract template variables from a quotation request
     *
     * @param QuotationRequest $quotationRequest
     * @return array
     */
    public function extractVariables(QuotationRequest $quotationRequest): array
    {
        $routing = $quotationRequest->routing ?? [];
        $cargo = $quotationRequest->cargo_details ?? [];
        $schedule = $quotationRequest->selectedSchedule;
        $pol = $routing['pol'] ?? '';
        $pod = $routing['pod'] ?? '';
        $por = $routing['por'] ?? '';
        $fdest = $routing['fdest'] ?? '';
        
        return [
            'contactPersonName' => $quotationRequest->requester_name ?? 'Valued Customer',
            'companyName' => $quotationRequest->requester_company ?? '',
            'POL' => $pol,
            'POD' => $pod,
            'POR' => $por,
            'FDEST' => $fdest,
            'CARGO' => $this->formatCargoDescription($cargo),
            'DIM_BEF_DELIVERY' => $this->formatCargoDetails($cargo),
            'TRANSHIPMENT' => $schedule?->transhipment_port ?? 'Direct',
            'FREQUENCY' => $schedule?->frequency ?? '',
            'TRANSIT_TIME' => $schedule?->transit_days ? $schedule->transit_days . ' days' : '',
            'NEXT_SAILING' => $schedule?->ets_pol?->format('d M Y') ?? '',
            'CARRIER' => $quotationRequest->preferred_carrier ?? $schedule?->carrier?->name ?? '',
            'VESSEL' => $schedule?->vessel_name ?? '',
            'VOYAGE' => $schedule?->voyage_number ?? '',
            'SERVICE_TYPE' => $this->formatServiceType($quotationRequest->service_type),
            'REQUEST_NUMBER' => $quotationRequest->request_number,
            'CARGO_DESCRIPTION' => $quotationRequest->cargo_description ?? $this->formatCargoDescription($cargo),
            'ROUTE_PHRASE' => $this->buildRoutePhrase($pol, $pod, $por, $fdest),
        ];
    }

    protected function buildRoutePhrase(string $pol, string $pod, string $por, string $fdest): string
    {
        $hasPor = !empty(trim($por));
        $hasFdest = !empty(trim($fdest));

        if (!$hasPor && !$hasFdest) {
            return 'Ex delivered terminal "' . $pol . '" to CFR "' . $pod . '"';
        }

        if ($hasPor && $hasFdest) {
            return trim($por . ' → ' . $pol . ' → ' . $pod . ' → ' . $fdest);
        }

        if ($hasPor) {
            return trim($por . ' → ' . $pod);
        }

        return trim($pol . ' → ' . $fdest);
    }

    /**
     * Render intro text for a quotation request
     *
     * @param QuotationRequest $quotationRequest
     * @param int|null $templateId Optional specific template ID
     * @return string|null
     */
    public function renderIntro(QuotationRequest $quotationRequest, ?int $templateId = null): ?string
    {
        // If specific template provided, use it
        if ($templateId) {
            $template = OfferTemplate::find($templateId);
        } else {
            // Auto-select appropriate intro template
            $template = $this->selectIntroTemplate(
                $quotationRequest->service_type,
                $quotationRequest->customer_type
            );
        }

        if (!$template) {
            Log::warning('No intro template found', [
                'service_type' => $quotationRequest->service_type,
                'customer_type' => $quotationRequest->customer_type
            ]);
            return null;
        }

        $variables = $this->extractVariables($quotationRequest);
        
        return $template->render($variables);
    }

    /**
     * Render end text for a quotation request
     *
     * @param QuotationRequest $quotationRequest
     * @param int|null $templateId Optional specific template ID
     * @return string|null
     */
    public function renderEnd(QuotationRequest $quotationRequest, ?int $templateId = null): ?string
    {
        // If specific template provided, use it
        if ($templateId) {
            $template = OfferTemplate::find($templateId);
        } else {
            // Auto-select appropriate end template
            $template = $this->selectEndTemplate(
                $quotationRequest->service_type,
                $quotationRequest->customer_type
            );
        }

        if (!$template) {
            Log::warning('No end template found', [
                'service_type' => $quotationRequest->service_type,
                'customer_type' => $quotationRequest->customer_type
            ]);
            return null;
        }

        $variables = $this->extractVariables($quotationRequest);
        
        return $template->render($variables);
    }

    /**
     * Apply templates to a quotation request and save
     *
     * @param QuotationRequest $quotationRequest
     * @param int|null $introTemplateId
     * @param int|null $endTemplateId
     * @return void
     */
    public function applyTemplates(QuotationRequest $quotationRequest, ?int $introTemplateId = null, ?int $endTemplateId = null): void
    {
        $variables = $this->extractVariables($quotationRequest);
        
        // Apply intro template
        if ($introTemplateId) {
            $introTemplate = OfferTemplate::find($introTemplateId);
            if ($introTemplate) {
                $quotationRequest->intro_template_id = $introTemplateId;
                $quotationRequest->intro_text = $introTemplate->render($variables);
            }
        } else {
            // Auto-select
            $introTemplate = $this->selectIntroTemplate(
                $quotationRequest->service_type,
                $quotationRequest->customer_type
            );
            if ($introTemplate) {
                $quotationRequest->intro_template_id = $introTemplate->id;
                $quotationRequest->intro_text = $introTemplate->render($variables);
            }
        }

        // Apply end template
        if ($endTemplateId) {
            $endTemplate = OfferTemplate::find($endTemplateId);
            if ($endTemplate) {
                $quotationRequest->end_template_id = $endTemplateId;
                $quotationRequest->end_text = $endTemplate->render($variables);
            }
        } else {
            // Auto-select
            $endTemplate = $this->selectEndTemplate(
                $quotationRequest->service_type,
                $quotationRequest->customer_type
            );
            if ($endTemplate) {
                $quotationRequest->end_template_id = $endTemplate->id;
                $quotationRequest->end_text = $endTemplate->render($variables);
            }
        }

        // Save template variables for future re-rendering
        $quotationRequest->template_variables = $variables;
        $quotationRequest->saveQuietly();
    }

    /**
     * Get available intro templates for service and customer type
     *
     * @param string $serviceType
     * @param string|null $customerType
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAvailableIntroTemplates(string $serviceType, ?string $customerType = null)
    {
        return OfferTemplate::getIntroTemplates($serviceType, $customerType);
    }

    /**
     * Get available end templates for service and customer type
     *
     * @param string $serviceType
     * @param string|null $customerType
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAvailableEndTemplates(string $serviceType, ?string $customerType = null)
    {
        return OfferTemplate::getEndTemplates($serviceType, $customerType);
    }

    /**
     * Auto-select best intro template
     *
     * @param string $serviceType
     * @param string|null $customerType
     * @return OfferTemplate|null
     */
    protected function selectIntroTemplate(string $serviceType, ?string $customerType = null): ?OfferTemplate
    {
        // Try exact match first (service + customer type)
        $template = OfferTemplate::active()
            ->ofType('intro')
            ->forService($serviceType)
            ->forCustomerType($customerType)
            ->ordered()
            ->first();

        // Fall back to service type only
        if (!$template) {
            $template = OfferTemplate::active()
                ->ofType('intro')
                ->forService($serviceType)
                ->whereNull('customer_type')
                ->ordered()
                ->first();
        }

        // Fall back to general template
        if (!$template) {
            $template = OfferTemplate::active()
                ->ofType('intro')
                ->where('service_type', 'GENERAL')
                ->ordered()
                ->first();
        }

        return $template;
    }

    /**
     * Auto-select best end template
     *
     * @param string $serviceType
     * @param string|null $customerType
     * @return OfferTemplate|null
     */
    protected function selectEndTemplate(string $serviceType, ?string $customerType = null): ?OfferTemplate
    {
        // Try exact match first (service + customer type)
        $template = OfferTemplate::active()
            ->ofType('end')
            ->forService($serviceType)
            ->forCustomerType($customerType)
            ->ordered()
            ->first();

        // Fall back to service type only
        if (!$template) {
            $template = OfferTemplate::active()
                ->ofType('end')
                ->forService($serviceType)
                ->whereNull('customer_type')
                ->ordered()
                ->first();
        }

        // Fall back to general template
        if (!$template) {
            $template = OfferTemplate::active()
                ->ofType('end')
                ->where('service_type', 'GENERAL')
                ->ordered()
                ->first();
        }

        return $template;
    }

    /**
     * Format cargo description for templates
     *
     * @param array $cargo
     * @return string
     */
    protected function formatCargoDescription(array $cargo): string
    {
        $type = $cargo['type'] ?? 'cargo';
        $quantity = $cargo['quantity'] ?? 1;
        
        if ($type === 'car' || $type === 'vehicle') {
            return $quantity . ' x ' . ($cargo['vehicle_type'] ?? 'vehicle') . 's';
        }
        
        if ($type === 'container') {
            return $quantity . ' x ' . ($cargo['container_type'] ?? '20ft') . ' container';
        }
        
        return $quantity . ' x ' . $type;
    }

    /**
     * Format detailed cargo information
     *
     * @param array $cargo
     * @return string
     */
    protected function formatCargoDetails(array $cargo): string
    {
        $details = [];
        
        if (isset($cargo['length'])) {
            $details[] = 'L: ' . $cargo['length'] . 'cm';
        }
        if (isset($cargo['width'])) {
            $details[] = 'W: ' . $cargo['width'] . 'cm';
        }
        if (isset($cargo['height'])) {
            $details[] = 'H: ' . $cargo['height'] . 'cm';
        }
        if (isset($cargo['weight'])) {
            $details[] = 'Weight: ' . $cargo['weight'] . 'kg';
        }
        
        return implode(', ', $details) ?: 'Standard dimensions';
    }

    /**
     * Format service type for display
     *
     * @param string $serviceType
     * @return string
     */
    protected function formatServiceType(string $serviceType): string
    {
        $serviceTypes = config('quotation.service_types', []);
        
        return $serviceTypes[$serviceType]['name'] ?? str_replace('_', ' ', $serviceType);
    }

    /**
     * Re-render templates with updated variables
     *
     * @param QuotationRequest $quotationRequest
     * @return void
     */
    public function reRenderTemplates(QuotationRequest $quotationRequest): void
    {
        // Extract fresh variables
        $variables = $this->extractVariables($quotationRequest);
        
        // Re-render intro if template exists
        if ($quotationRequest->intro_template_id && $quotationRequest->introTemplate) {
            $quotationRequest->intro_text = $quotationRequest->introTemplate->render($variables);
        }
        
        // Re-render end if template exists
        if ($quotationRequest->end_template_id && $quotationRequest->endTemplate) {
            $quotationRequest->end_text = $quotationRequest->endTemplate->render($variables);
        }
        
        // Update template variables
        $quotationRequest->template_variables = $variables;
        $quotationRequest->saveQuietly();
    }
}

