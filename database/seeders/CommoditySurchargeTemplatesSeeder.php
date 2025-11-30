<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\RobawsArticleCache;
use Illuminate\Support\Facades\DB;

class CommoditySurchargeTemplatesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder documents the structure of commodity-based surcharge templates.
     * Templates are applied manually via the Filament UI "Add Template" feature.
     * 
     * Template Structure:
     * - Each template defines a set of child articles (surcharges) with their child_type
     * - Templates are organized by commodity type (Excavator, Bulldozer, Cars, Containers)
     * - Each child in a template has: article_code pattern, child_type, conditions (if conditional)
     */
    public function run(): void
    {
        $this->command->info('Commodity Surcharge Templates Structure:');
        $this->command->info('');
        
        $templates = [
            'Excavator' => [
                [
                    'name' => 'Overwidth Surcharge',
                    'type' => 'conditional',
                    'conditions' => ['dimensions' => ['width_m_gt' => 2.50]],
                    'description' => 'Applied when width > 2.50m',
                ],
                [
                    'name' => 'Track Surcharge',
                    'type' => 'mandatory',
                    'description' => 'Always applied for excavators',
                ],
                [
                    'name' => 'Lashing Fee',
                    'type' => 'mandatory',
                    'description' => 'Always applied for excavators',
                ],
                [
                    'name' => 'Handling Fee',
                    'type' => 'mandatory',
                    'description' => 'Always applied for excavators',
                ],
            ],
            'Bulldozer' => [
                [
                    'name' => 'Track Surcharge',
                    'type' => 'mandatory',
                    'description' => 'Always applied for bulldozers',
                ],
                [
                    'name' => 'Towing',
                    'type' => 'conditional',
                    'conditions' => ['custom' => 'if self-driving = false'],
                    'description' => 'Applied if vehicle cannot self-drive',
                ],
            ],
            'Cars' => [
                [
                    'name' => 'Inspection',
                    'type' => 'optional',
                    'description' => 'Customer can choose to include',
                ],
                [
                    'name' => 'Maritime Insurance',
                    'type' => 'optional',
                    'description' => 'Customer can choose to include',
                ],
                [
                    'name' => 'War Risk',
                    'type' => 'conditional',
                    'conditions' => ['route' => ['pod' => ['CNSHA', 'CNQIN']]],
                    'description' => 'Applied for certain PODs',
                ],
            ],
            'Containers' => [
                [
                    'name' => 'Handling Fee',
                    'type' => 'mandatory',
                    'description' => 'Always applied for containers',
                ],
                [
                    'name' => 'Customs Brokerage',
                    'type' => 'optional',
                    'description' => 'Customer can choose to include',
                ],
            ],
        ];

        foreach ($templates as $commodity => $surcharges) {
            $this->command->info("Template: {$commodity}");
            foreach ($surcharges as $surcharge) {
                $type = strtoupper($surcharge['type']);
                $this->command->info("  - {$surcharge['name']} ({$type}): {$surcharge['description']}");
                if (isset($surcharge['conditions'])) {
                    $this->command->info("    Conditions: " . json_encode($surcharge['conditions'], JSON_PRETTY_PRINT));
                }
            }
            $this->command->info('');
        }

        $this->command->info('Note: Templates are applied manually via Filament UI.');
        $this->command->info('To use a template:');
        $this->command->info('1. Open an article in Filament');
        $this->command->info('2. Go to "Composite Items / Surcharges" tab');
        $this->command->info('3. Use "Add Template" button (to be implemented)');
        $this->command->info('4. Select commodity type');
        $this->command->info('5. Template surcharges will be pre-filled');
    }
}
