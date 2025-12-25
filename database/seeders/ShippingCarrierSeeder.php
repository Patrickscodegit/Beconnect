<?php

namespace Database\Seeders;

use App\Models\ShippingCarrier;
use Illuminate\Database\Seeder;

class ShippingCarrierSeeder extends Seeder
{
    public function run()
    {
        $carriers = [
            [
                'name' => 'Seatrade',
                'code' => 'SEATRADE',
                'website_url' => 'https://www.seatrade.com/schedules/',
                'api_endpoint' => 'https://www.seatrade.com/api/schedules',
                'service_types' => json_encode(['RORO', 'FCL', 'LCL']),
                'specialization' => json_encode([
                    'reefer_services' => true,
                    'french_west_indies' => true,
                    'suriname' => true
                ]),
                'service_level' => 'Regional',
                'is_active' => true
            ],
            [
                'name' => 'Wallenius Wilhelmsen',
                'code' => 'WWL',
                'website_url' => 'https://www.walleniuswilhelmsen.com/schedules',
                'service_types' => json_encode(['RORO']),
                'specialization' => json_encode([
                    'end_to_end_logistics' => true,
                    'vehicle_processing' => true
                ]),
                'service_level' => 'Premium'
            ],
            [
                'name' => 'Höegh Autoliners',
                'code' => 'HOEGH',
                'website_url' => 'https://www.hoeghautoliners.com/schedules',
                'service_types' => json_encode(['RORO']),
                'specialization' => json_encode([
                    'sustainable_shipping' => true,
                    'biofuel_leader' => true,
                    'aurora_class_fleet' => true
                ]),
                'service_level' => 'Premium'
            ],
            [
                'name' => 'EUKOR Car Carriers',
                'code' => 'EUKOR',
                'website_url' => 'https://www.eukor.com/schedules',
                'service_types' => json_encode(['RORO']),
                'specialization' => json_encode([
                    'pure_automotive_transport' => true,
                    'high_heavy_cargo' => true
                ]),
                'service_level' => 'Premium'
            ],
            [
                'name' => 'NYK RORO',
                'code' => 'NYK',
                'website_url' => 'https://www.nykroro.com/schedules',
                'service_types' => json_encode(['RORO']),
                'specialization' => json_encode([
                    'vehicle_transportation' => true,
                    'global_routes' => true
                ]),
                'service_level' => 'Premium'
            ],
            [
                'name' => 'UECC',
                'code' => 'UECC',
                'website_url' => 'https://www.uecc.com/schedules',
                'service_types' => json_encode(['RORO']),
                'specialization' => json_encode([
                    'european_routes' => true,
                    'vehicle_transportation' => true
                ]),
                'service_level' => 'Standard'
            ],
            [
                'name' => 'Sallaum Lines',
                'code' => 'SALLAUM',
                'website_url' => 'https://www.sallaumlines.com/schedules',
                'service_types' => json_encode(['RORO']),
                'specialization' => json_encode([
                    'africa_routes' => true,
                    'esg_focus' => true
                ]),
                'service_level' => 'Standard'
            ],
            [
                'name' => 'Marinvests',
                'code' => 'MARINVESTS',
                'website_url' => 'https://www.marinvests.com/schedules',
                'service_types' => json_encode(['RORO']),
                'specialization' => json_encode([
                    'congo_routes' => true,
                    'belgium_congo' => true
                ]),
                'service_level' => 'Regional'
            ],
            [
                'name' => 'Nirint Shipping',
                'code' => 'NIRINT',
                'website_url' => 'https://www.nirint.com/schedules',
                'service_types' => json_encode(['RORO']),
                'specialization' => json_encode([
                    'caribbean_routes' => true,
                    'europe_caribbean' => true
                ]),
                'service_level' => 'Regional'
            ],
            [
                'name' => 'Europe Caribbean Line',
                'code' => 'ECL',
                'website_url' => 'https://www.europe-caribbean.com/schedules',
                'service_types' => json_encode(['RORO', 'FCL']),
                'specialization' => json_encode([
                    'oil_industry_logistics' => true,
                    'south_caribbean' => true
                ]),
                'service_level' => 'Standard'
            ],
            [
                'name' => '"K" Line Global RORO Service',
                'code' => 'KLINE',
                'website_url' => 'https://www.kline.com/schedules',
                'service_types' => json_encode(['RORO']),
                'specialization' => json_encode([
                    'atlantic_trade' => true,
                    'north_america_mexico_gulf' => true
                ]),
                'service_level' => 'Premium'
            ],
            [
                'name' => 'Marfret',
                'code' => 'MARFRET',
                'website_url' => 'https://www.marfret.com/schedules',
                'service_types' => json_encode(['RORO', 'FCL', 'LCL']),
                'specialization' => json_encode([
                    'mediterranean_caribbean' => true,
                    'medcar' => true,
                    'guyane_amazonie' => true
                ]),
                'service_level' => 'Standard'
            ],
            [
                'name' => 'Geest Line',
                'code' => 'GEEST',
                'website_url' => 'https://www.geestline.com/schedules',
                'service_types' => json_encode(['RORO', 'FCL', 'LCL', 'BREAKBULK']),
                'specialization' => json_encode([
                    'caribbean_inter_island' => true,
                    'europe_caribbean_direct' => true,
                    'break_bulk_cargo' => true
                ]),
                'service_level' => 'Standard'
            ],
            [
                'name' => 'NMT Shipping',
                'code' => 'NMT',
                'website_url' => 'https://www.nmtshipping.com/schedules',
                'service_types' => json_encode(['RORO']),
                'specialization' => json_encode([
                    'vehicle_transportation' => true,
                    'global_routes' => true
                ]),
                'service_level' => 'Standard'
            ],
            [
                'name' => 'Grimaldi GNET',
                'code' => 'GRIMALDI',
                'website_url' => 'https://www.grimaldi.com/schedules',
                'service_types' => json_encode(['RORO']),
                'specialization' => json_encode([
                    'africa_routes' => true,
                    'vehicle_transportation' => true
                ]),
                'service_level' => 'Standard'
            ]
        ];
        
        foreach ($carriers as $carrier) {
            // Add missing fields if not present
            if (!isset($carrier['api_endpoint'])) {
                $carrier['api_endpoint'] = $carrier['website_url'] . '/api';
            }
            if (!isset($carrier['is_active'])) {
                $carrier['is_active'] = true;
            }
            
            ShippingCarrier::create($carrier);
        }
    }
}