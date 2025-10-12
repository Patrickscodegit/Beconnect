<?php

namespace Database\Seeders;

use App\Models\OfferTemplate;
use Illuminate\Database\Seeder;

class OfferTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = $this->getTemplates();

        foreach ($templates as $templateData) {
            OfferTemplate::updateOrCreate(
                ['template_code' => $templateData['template_code']],
                $templateData
            );
        }

        $this->command->info('Created ' . count($templates) . ' offer templates.');
    }

    /**
     * Get all template definitions
     */
    private function getTemplates(): array
    {
        return [
            // RORO Import
            [
                'template_code' => 'RORO_IMP_INTRO_GENERAL',
                'template_name' => 'RORO Import - Intro - General',
                'template_type' => 'intro',
                'service_type' => 'RORO_IMPORT',
                'customer_type' => null,
                'content' => <<<'EOT'
Dear ${contactPersonName},

Thank you for your inquiry regarding the import of ${CARGO} from ${POL} to ${POD}.

We are pleased to offer you our RORO (Roll-on/Roll-off) import service via ${CARRIER}. Your cargo will be transported aboard the vessel ${VESSEL} (Voyage: ${VOYAGE}), with an estimated transit time of ${TRANSIT_TIME}.

Next sailing: ${NEXT_SAILING}

Please find below our competitive quotation:
EOT,
                'available_variables' => ['contactPersonName', 'CARGO', 'POL', 'POD', 'CARRIER', 'VESSEL', 'VOYAGE', 'TRANSIT_TIME', 'NEXT_SAILING'],
                'sort_order' => 1,
                'is_active' => true,
            ],
            
            [
                'template_code' => 'RORO_IMP_END_GENERAL',
                'template_name' => 'RORO Import - End - General',
                'template_type' => 'end',
                'service_type' => 'RORO_IMPORT',
                'customer_type' => null,
                'content' => <<<'EOT'
This quotation is valid for 14 days from the date of issue.

All prices are in EUR and subject to the following conditions:
- Customs clearance documents must be provided in advance
- Cargo must be roadworthy and meet shipping requirements
- Payment terms as agreed

Should you have any questions or require additional services, please do not hesitate to contact us.

We look forward to serving you.

Best regards,
Belgaco Team

Reference: ${REQUEST_NUMBER}
EOT,
                'available_variables' => ['REQUEST_NUMBER'],
                'sort_order' => 1,
                'is_active' => true,
            ],

            // RORO Export
            [
                'template_code' => 'RORO_EXP_INTRO_GENERAL',
                'template_name' => 'RORO Export - Intro - General',
                'template_type' => 'intro',
                'service_type' => 'RORO_EXPORT',
                'customer_type' => null,
                'content' => <<<'EOT'
Dear ${contactPersonName},

Thank you for choosing Belgaco for your export needs.

We are pleased to provide you with a quotation for the export of ${CARGO} from ${POR} to ${FDEST} via ${POL} to ${POD}.

Service Details:
- Carrier: ${CARRIER}
- Transit time: ${TRANSIT_TIME}
- Frequency: ${FREQUENCY}
- Next sailing: ${NEXT_SAILING}

Please find our comprehensive quotation below:
EOT,
                'available_variables' => ['contactPersonName', 'CARGO', 'POR', 'FDEST', 'POL', 'POD', 'CARRIER', 'TRANSIT_TIME', 'FREQUENCY', 'NEXT_SAILING'],
                'sort_order' => 1,
                'is_active' => true,
            ],

            [
                'template_code' => 'RORO_EXP_END_GENERAL',
                'template_name' => 'RORO Export - End - General',
                'template_type' => 'end',
                'service_type' => 'RORO_EXPORT',
                'customer_type' => null,
                'content' => <<<'EOT'
Additional Services Available:
- Pre-carriage from your location to our warehouse
- Warehouse storage and handling
- Export customs clearance
- Insurance coverage

Booking deadline: 5 working days before departure.

This quotation is valid for 14 days and is subject to space availability.

Please confirm your booking at your earliest convenience.

Best regards,
Belgaco Export Team

Reference: ${REQUEST_NUMBER}
EOT,
                'available_variables' => ['REQUEST_NUMBER'],
                'sort_order' => 1,
                'is_active' => true,
            ],

            // FCL Export
            [
                'template_code' => 'FCL_EXP_INTRO_GENERAL',
                'template_name' => 'FCL Export - Intro - General',
                'template_type' => 'intro',
                'service_type' => 'FCL_EXPORT',
                'customer_type' => null,
                'content' => <<<'EOT'
Dear ${contactPersonName},

Thank you for your FCL (Full Container Load) export inquiry.

We are pleased to offer you a competitive quotation for the shipment of ${CARGO} in a dedicated container from ${POR} to ${FDEST}.

Route: ${POL} to ${POD}
Transhipment: ${TRANSHIPMENT}
Transit time: ${TRANSIT_TIME}

Our quotation includes:
EOT,
                'available_variables' => ['contactPersonName', 'CARGO', 'POR', 'FDEST', 'POL', 'POD', 'TRANSHIPMENT', 'TRANSIT_TIME'],
                'sort_order' => 1,
                'is_active' => true,
            ],

            [
                'template_code' => 'FCL_EXP_END_GENERAL',
                'template_name' => 'FCL Export - End - General',
                'template_type' => 'end',
                'service_type' => 'FCL_EXPORT',
                'customer_type' => null,
                'content' => <<<'EOT'
Container Specifications:
- 20ft and 40ft containers available
- Loading at your facility or our warehouse
- Container delivery and pick-up included

Additional services available upon request.

Validity: 7 days (subject to carrier rate changes)

Please confirm your booking and provide:
1. Commercial invoice
2. Packing list
3. Export declaration documents

We look forward to handling your shipment.

Best regards,
Belgaco FCL Team

Reference: ${REQUEST_NUMBER}
EOT,
                'available_variables' => ['REQUEST_NUMBER'],
                'sort_order' => 1,
                'is_active' => true,
            ],

            // General Fallback
            [
                'template_code' => 'GENERAL_INTRO',
                'template_name' => 'General - Intro',
                'template_type' => 'intro',
                'service_type' => 'GENERAL',
                'customer_type' => null,
                'content' => <<<'EOT'
Dear ${contactPersonName},

Thank you for your inquiry.

We are pleased to provide you with a quotation for ${SERVICE_TYPE} services for ${CARGO} from ${POL} to ${POD}.

Please find our competitive quotation below:
EOT,
                'available_variables' => ['contactPersonName', 'SERVICE_TYPE', 'CARGO', 'POL', 'POD'],
                'sort_order' => 999,
                'is_active' => true,
            ],

            [
                'template_code' => 'GENERAL_END',
                'template_name' => 'General - End',
                'template_type' => 'end',
                'service_type' => 'GENERAL',
                'customer_type' => null,
                'content' => <<<'EOT'
This quotation is valid for 14 days from the date of issue.

Should you require any additional information or services, please do not hesitate to contact us.

We look forward to serving you and building a long-term partnership.

Best regards,
Belgaco Team

Reference: ${REQUEST_NUMBER}
EOT,
                'available_variables' => ['REQUEST_NUMBER'],
                'sort_order' => 999,
                'is_active' => true,
            ],
        ];
    }
}
