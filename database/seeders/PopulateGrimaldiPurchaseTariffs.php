<?php

namespace Database\Seeders;

use App\Models\CarrierArticleMapping;
use App\Models\CarrierCategoryGroup;
use App\Models\CarrierPortGroup;
use App\Models\CarrierPurchaseTariff;
use App\Models\Port;
use App\Models\RobawsArticleCache;
use App\Models\ShippingCarrier;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class PopulateGrimaldiPurchaseTariffs extends Seeder
{
    /**
     * Purchase tariffs from PDF, organized by port code and category
     * Includes base freight and all surcharges
     * Effective date: 2026-01-01
     * Source: GRIMALDI BELGIUM - Tariff Sheet West Africa - used vehicles (1/01/26)
     * 
     * Ports present in PDF but not seedable yet:
     * 
     * - Bata (separate destination article exists) — port code TBD
     * - Malabo (separate destination article exists) — port code TBD
     * - Luanda — port code TBD
     * - Monrovia — port code TBD
     * - Tema — port code TBD
     * - Takoradi — port code TBD
     * - Tenerife — port code TBD (PDF groups CAS/Tenerife; we do NOT group)
     * 
     * Requirements before seeding:
     * 1) Port exists in ports table
     * 2) Destination article exists (robaws_articles_cache) with GANR* pattern
     * 3) CarrierArticleMapping exists for GRIMALDI
     * 
     * Once mappings exist, ports will appear automatically in overview.
     */
    private array $pdfData = [
        'ABJ' => [ // Abidjan (ARIDJAN in PDF)
            'CAR' => [
                'base_freight' => 560,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 12,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 2,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 710,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 10,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1260,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 10,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 450,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => 50,
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'FNA' => [ // Freetown
            'CAR' => [
                'base_freight' => 875,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 40,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 5,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 985,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 40,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1740,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 40,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 765,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => 80,
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'BJL' => [ // Banjul (SABJUL in PDF)
            'CAR' => [
                'base_freight' => 675,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 26,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 5,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 1003,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 26,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 2029,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 26,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 880,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => 26,
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'LOS' => [ // Lagos
            'CAR' => [
                'base_freight' => 651,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Lagos
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 761,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Lagos
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1220,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Lagos
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 540,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => null, // Not in PDF for Lagos
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'CAS' => [ // Casablanca (CABARLANCA/TEHERIFE in PDF)
            'CAR' => [
                'base_freight' => 655,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Casablanca
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 765,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Casablanca
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1570,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Casablanca
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 605,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => null, // Not in PDF for Casablanca
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'TFN' => [ // Tenerife (CASABLANCA/TENERIFE in PDF - same rates as Casablanca)
            'CAR' => [
                'base_freight' => 655,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 765,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1570,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 605,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'CKY' => [ // Conakry (CONAKRY in PDF) - includes congestion + ICCM
            'CAR' => [
                'base_freight' => 555,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 52,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'iccm' => 67,
            ],
            'SMALL_VAN' => [
                'base_freight' => 735,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 80,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => ['amount' => 100, 'unit' => 'LUMPSUM'],
                'iccm' => 67,
            ],
            'BIG_VAN' => [
                'base_freight' => 1420,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 93,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => ['amount' => 200, 'unit' => 'LUMPSUM'],
                'iccm' => 67,
            ],
            'LM' => [
                'base_freight' => 450,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => null, // PDF says "see below" - complex calculation
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => ['amount' => 100, 'unit' => 'LM'],
                'iccm' => 67,
            ],
        ],
        'LFW' => [ // Lome (LUNES in PDF)
            'CAR' => [
                'base_freight' => 565,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Lome
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 645,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Lome
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1330,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Lome
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 465,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => null, // Not in PDF for Lome
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'COO' => [ // Cotonou
            'CAR' => [
                'base_freight' => 605,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Cotonou
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => ['amount' => 150, 'unit' => 'LUMPSUM'], // "Cogn. addit." in PDF
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 685,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Cotonou
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => ['amount' => 100, 'unit' => 'LUMPSUM'], // "Cogn. addit." in PDF
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1470,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null, // Not in PDF for Cotonou
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => ['amount' => 200, 'unit' => 'LUMPSUM'], // "Cogn. addit." in PDF
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 465,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => null, // Not in PDF for Cotonou
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => ['amount' => 100, 'unit' => 'LM'], // "Cogn. addit." in PDF
                'iccm' => null,
            ],
        ],
        'DKR' => [ // Dakar
            'CAR' => [
                'base_freight' => 525,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 635,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1320,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 460,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'DLA' => [ // Douala
            'CAR' => [
                'base_freight' => 855,
                'baf' => 75, // Default LUMPSUM
                'ets' => 29.0,
                'port_additional' => 50,
                'admin_fxe' => 26,
                'thc' => 10,
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 985,
                'baf' => 75,
                'ets' => 29.0,
                'port_additional' => 50,
                'admin_fxe' => 26,
                'thc' => 10,
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1770,
                'baf' => 150,
                'ets' => 58.0,
                'port_additional' => 50,
                'admin_fxe' => 26,
                'thc' => 10,
                'measurement_costs' => 20,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => ['amount' => 735, 'unit' => 'LM'],
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => ['amount' => 50, 'unit' => 'LM'], // Special: per LM unit
                'admin_fxe' => 26, // Fixed EUR
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25, // Fixed EUR
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'LAD' => [ // Luanda (JUANDA in PDF)
            'CAR' => [
                'base_freight' => 820,
                'baf' => ['amount' => 95, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 40,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 950,
                'baf' => ['amount' => 95, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 40,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 2220,
                'baf' => ['amount' => 190, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 40,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 810,
                'baf' => ['amount' => 95, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => 150,
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'ROB' => [ // Monrovia (HONROVIA in PDF) - same rates as Luanda
            'CAR' => [
                'base_freight' => 820,
                'baf' => ['amount' => 95, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 40,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 950,
                'baf' => ['amount' => 95, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 40,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 2220,
                'baf' => ['amount' => 190, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 40,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 810,
                'baf' => ['amount' => 95, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => 150,
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'BTA' => [ // Bata (Sata/Malabo in PDF - using Bata rates)
            'CAR' => [
                'base_freight' => 1010,
                'baf' => ['amount' => 95, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 1120,
                'baf' => ['amount' => 95, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1400,
                'baf' => ['amount' => 190, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 920,
                'baf' => ['amount' => 95, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'MAL' => [ // Malabo (Sata/Malabo in PDF - using Malabo rates, same as Bata)
            'CAR' => [
                'base_freight' => 1010,
                'baf' => ['amount' => 95, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 1120,
                'baf' => ['amount' => 95, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1400,
                'baf' => ['amount' => 190, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 20,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 920,
                'baf' => ['amount' => 95, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'PNR' => [ // Pointe-Noire (POINTE NOIRE in PDF)
            'CAR' => [
                'base_freight' => 695,
                'baf' => ['amount' => 95, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 775,
                'baf' => ['amount' => 95, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1705,
                'baf' => ['amount' => 190, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 630,
                'baf' => ['amount' => 95, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'LBV' => [ // Libreville (LIBREVILLE in PDF)
            'CAR' => [
                'base_freight' => 820,
                'baf' => ['amount' => 95, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 20,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 950,
                'baf' => ['amount' => 95, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 20,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1750,
                'baf' => ['amount' => 190, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 20,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 740,
                'baf' => ['amount' => 95, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => 20,
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'NKC' => [ // Nouakchott (NOUAKCHOTT in PDF) - totals: CAR 925, SVAN 1050, BVAN 1909, LM 1909
            'CAR' => [
                'base_freight' => 785, // 925 - 75 - 29 - 26 - 10 = 785
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 910, // 1050 - 75 - 29 - 26 - 10 = 910
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1640, // 1909 - 150 - 58 - 26 - 10 - 25 = 1640
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 765, // Estimated to match 1909 total (1909 - 75 - 17.3 - 26 - 20 - 25 = 765.7, rounded)
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => null,
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'TEM' => [ // Tema (TEMA/TAKORADI in PDF) - totals: CAR 785, SVAN 895, BVAN 1815, LM (no total shown)
            'CAR' => [
                'base_freight' => 590,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 55, // Port addit. (50) + Freight Tax (5) = 55
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 700,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 55, // Port addit. (50) + Freight Tax (5) = 55
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1491,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 55, // Port addit. (50) + Freight Tax (5) = 55
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 500,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => 120, // Port addit. (100) + Freight Tax (20) = 120
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
        'TKR' => [ // Takoradi (TEMA/TAKORADI in PDF - same rates as Tema)
            'CAR' => [
                'base_freight' => 590,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 55, // Port addit. (50) + Freight Tax (5) = 55
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'SMALL_VAN' => [
                'base_freight' => 700,
                'baf' => ['amount' => 75, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 29.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 55, // Port addit. (50) + Freight Tax (5) = 55
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 0,
                'congestion' => null,
                'iccm' => null,
            ],
            'BIG_VAN' => [
                'base_freight' => 1491,
                'baf' => ['amount' => 150, 'unit' => 'LUMPSUM'],
                'ets' => ['amount' => 58.0, 'unit' => 'LUMPSUM'],
                'port_additional' => 55, // Port addit. (50) + Freight Tax (5) = 55
                'admin_fxe' => 26,
                'thc' => ['amount' => 10, 'unit' => 'LUMPSUM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
            'LM' => [
                'base_freight' => 500,
                'baf' => ['amount' => 75, 'unit' => 'LM'],
                'ets' => ['amount' => 17.3, 'unit' => 'LM'],
                'port_additional' => 120, // Port addit. (100) + Freight Tax (20) = 120
                'admin_fxe' => 26,
                'thc' => ['amount' => 20, 'unit' => 'LM'],
                'measurement_costs' => 25,
                'congestion' => null,
                'iccm' => null,
            ],
        ],
    ];

    /**
     * Combined port labels from PDF that map to multiple port codes
     */
    private array $combinedPorts = [
        'SATA/MALABO' => ['MLW'], // May need to check actual port codes
        'CASABLANCA/TENERIFE' => ['CAS'], // Using CAS (Casablanca)
        'TEMA/TAKORADI' => ['TEM'], // May need to check actual port codes
    ];

    /**
     * Category to article code suffix mapping
     */
    private array $categorySuffixes = [
        'CAR' => 'CAR',
        'SMALL_VAN' => 'SV',
        'BIG_VAN' => 'BV',
        'LM' => 'HH',
    ];

    /**
     * Category to commodity type mapping (for article lookup)
     */
    private array $categoryToCommodityType = [
        'CAR' => 'Car',
        'SMALL_VAN' => 'Small Van',
        'BIG_VAN' => 'Big Van',
        'LM' => 'LM Cargo',
    ];

    /**
     * Port code to article code pattern mapping
     * Some ports use different codes in article names (e.g., LFW -> LOM, BJL -> BAN)
     */
    private array $portCodeToArticlePattern = [
        'LFW' => 'LOM', // Lome uses LOM in article codes
        'BJL' => 'BAN', // Banjul uses BAN in article codes
        // TFN removed - Tenerife is a separate port, will create placeholder articles
    ];

    /**
     * Port aliases: allow reuse of PDF rates without UI grouping
     * Key = target port code, Value = source port code to clone from
     * NOTE: TFN now has direct PDF data, so alias removed
     */
    private array $pdfAliases = [
        // TFN removed - now has direct PDF data
    ];

    private const EFFECTIVE_DATE = '2026-01-01';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🚢 Populating Grimaldi Purchase Tariffs from PDF (effective 2026-01-01)...');
        $this->command->newLine();

        // Find Grimaldi carrier
        $carrier = ShippingCarrier::where('code', 'GRIMALDI')->first();
        if (!$carrier) {
            $this->command->error('❌ Grimaldi carrier not found. Please run ShippingCarrierSeeder first.');
            return;
        }

        $this->command->info("✓ Found carrier: {$carrier->name} (ID: {$carrier->id})");

        // Get category groups
        $categoryGroups = $carrier->categoryGroups()->where('is_active', true)->get()->keyBy('code');
        
        // Get WAF port group
        $wafPortGroup = CarrierPortGroup::where('carrier_id', $carrier->id)
            ->where('code', 'Grimaldi_WAF')
            ->first();
        
        $wafPortGroupIds = $wafPortGroup ? [$wafPortGroup->id] : null;

        $stats = [
            'ports_processed' => 0,
            'mappings_found' => 0,
            'mappings_created' => 0,
            'tariffs_created' => 0,
            'tariffs_updated' => 0,
            'warnings' => 0,
        ];

        // Process each port in PDF data
        foreach ($this->pdfData as $portCode => $categories) {
            $port = Port::where('code', $portCode)->first();
            if (!$port) {
                $this->command->warn("  ⚠ Port code '{$portCode}' not found in database. Skipping.");
                $stats['warnings']++;
                continue;
            }

            $this->command->info("  Processing port: {$port->name} ({$portCode})");
            $stats['ports_processed']++;

            // Process each category for this port
            foreach ($categories as $category => $categoryData) {
                if (!is_array($categoryData) || !isset($categoryData['base_freight'])) {
                    continue; // Skip invalid entries
                }

                // Find or create mapping
                $mapping = $this->findOrCreateMapping(
                    $carrier,
                    $port,
                    $category,
                    $categoryGroups,
                    $wafPortGroupIds,
                    $stats
                );

                if (!$mapping) {
                    continue; // Warning already logged
                }

                // Deactivate older tariffs
                $this->deactivateOlderTariffs($mapping);

                // Create or update purchase tariff with all surcharges
                // Use Carbon::parse to ensure consistent date handling for updateOrCreate
                
                // Normalize all values using helper
                $baseFreight = $this->normalizeValue(
                    $categoryData['base_freight'] ?? null,
                    $category === 'LM' ? 'LM' : 'LUMPSUM'
                );
                $baf = $this->normalizeValue($categoryData['baf'] ?? null, 'LUMPSUM');
                $ets = $this->normalizeValue($categoryData['ets'] ?? null, 'LUMPSUM');
                $portAdditional = $this->normalizeValue($categoryData['port_additional'] ?? null, 'LUMPSUM');
                $adminFxe = $this->normalizeValue($categoryData['admin_fxe'] ?? null, 'LUMPSUM');
                $thc = $this->normalizeValue($categoryData['thc'] ?? null, 'LUMPSUM');
                $measurementCosts = $this->normalizeValue($categoryData['measurement_costs'] ?? null, 'LUMPSUM');
                $congestion = $this->normalizeValue($categoryData['congestion'] ?? null, 'LUMPSUM');
                $iccm = $this->normalizeValue($categoryData['iccm'] ?? null, 'LUMPSUM');
                
                // Build tariff data array
                $tariffData = $this->buildTariffData(
                    $baseFreight, $baf, $ets, $portAdditional, $adminFxe, 
                    $thc, $measurementCosts, $congestion, $iccm
                );
                
                $tariff = CarrierPurchaseTariff::updateOrCreate(
                    [
                        'carrier_article_mapping_id' => $mapping->id,
                        'effective_from' => Carbon::parse(self::EFFECTIVE_DATE)->format('Y-m-d'),
                    ],
                    $tariffData
                );

                $baseAmount = $baseFreight['amount'];
                $unit = $baseFreight['unit'] ?? ($category === 'LM' ? 'LM' : 'EUR');
                if ($tariff->wasRecentlyCreated) {
                    $stats['tariffs_created']++;
                    $this->command->info("    ✓ Created tariff: {$category} = {$baseAmount} {$unit}");
                } else {
                    $stats['tariffs_updated']++;
                    $this->command->info("    ✓ Updated tariff: {$category} = {$baseAmount} {$unit}");
                }
            }
        }

        // Process aliases: ports that should use another port's rates
        foreach ($this->pdfAliases as $targetPortCode => $sourcePortCode) {
            // Skip if target port already has PDF data
            if (isset($this->pdfData[$targetPortCode])) {
                continue;
            }
            
            // Check if source port has PDF data
            if (!isset($this->pdfData[$sourcePortCode])) {
                $this->command->warn("  ⚠ Port {$targetPortCode} has alias but source {$sourcePortCode} has no PDF data. Skipping.");
                $stats['warnings']++;
                continue;
            }
            
            // Check if target port exists in database
            $targetPort = Port::where('code', $targetPortCode)->first();
            if (!$targetPort) {
                $this->command->warn("  ⚠ Port code '{$targetPortCode}' (alias target) not found in database. Skipping.");
                $stats['warnings']++;
                continue;
            }
            
            $this->command->info("  Processing port (alias): {$targetPort->name} ({$targetPortCode}) using rates from {$sourcePortCode}");
            $stats['ports_processed']++;
            
            // Use source port's categories
            $categories = $this->pdfData[$sourcePortCode];
            
            // Process each category for this port
            foreach ($categories as $category => $categoryData) {
                if (!is_array($categoryData) || !isset($categoryData['base_freight'])) {
                    continue; // Skip invalid entries
                }

                // Find or create mapping
                $mapping = $this->findOrCreateMapping(
                    $carrier,
                    $targetPort,
                    $category,
                    $categoryGroups,
                    $wafPortGroupIds,
                    $stats
                );

                if (!$mapping) {
                    $this->command->warn("    ⚠ Port {$targetPortCode} has alias but no mappings exist. Skipping.");
                    continue; // Warning already logged
                }

                // Deactivate older tariffs
                $this->deactivateOlderTariffs($mapping);

                // Create or update purchase tariff (same logic as main loop)
                $baseFreight = $this->normalizeValue(
                    $categoryData['base_freight'] ?? null,
                    $category === 'LM' ? 'LM' : 'LUMPSUM'
                );
                $baf = $this->normalizeValue($categoryData['baf'] ?? null, 'LUMPSUM');
                $ets = $this->normalizeValue($categoryData['ets'] ?? null, 'LUMPSUM');
                $portAdditional = $this->normalizeValue($categoryData['port_additional'] ?? null, 'LUMPSUM');
                $adminFxe = $this->normalizeValue($categoryData['admin_fxe'] ?? null, 'LUMPSUM');
                $thc = $this->normalizeValue($categoryData['thc'] ?? null, 'LUMPSUM');
                $measurementCosts = $this->normalizeValue($categoryData['measurement_costs'] ?? null, 'LUMPSUM');
                $congestion = $this->normalizeValue($categoryData['congestion'] ?? null, 'LUMPSUM');
                $iccm = $this->normalizeValue($categoryData['iccm'] ?? null, 'LUMPSUM');
                
                $tariffData = $this->buildTariffData(
                    $baseFreight, $baf, $ets, $portAdditional, $adminFxe, 
                    $thc, $measurementCosts, $congestion, $iccm
                );
                
                $tariff = CarrierPurchaseTariff::updateOrCreate(
                    [
                        'carrier_article_mapping_id' => $mapping->id,
                        'effective_from' => Carbon::parse(self::EFFECTIVE_DATE)->format('Y-m-d'),
                    ],
                    $tariffData
                );

                $baseAmount = $baseFreight['amount'];
                $unit = $baseFreight['unit'] ?? ($category === 'LM' ? 'LM' : 'EUR');
                if ($tariff->wasRecentlyCreated) {
                    $stats['tariffs_created']++;
                    $this->command->info("    ✓ Created tariff: {$category} = {$baseAmount} {$unit}");
                } else {
                    $stats['tariffs_updated']++;
                    $this->command->info("    ✓ Updated tariff: {$category} = {$baseAmount} {$unit}");
                }
            }
        }

        $this->command->newLine();
        $this->command->info('✅ Purchase tariffs populated successfully!');
        $this->command->info("  Ports processed: {$stats['ports_processed']}");
        $this->command->info("  Mappings found: {$stats['mappings_found']}");
        $this->command->info("  Mappings created: {$stats['mappings_created']}");
        $this->command->info("  Tariffs created: {$stats['tariffs_created']}");
        $this->command->info("  Tariffs updated: {$stats['tariffs_updated']}");
        if ($stats['warnings'] > 0) {
            $this->command->warn("  Warnings: {$stats['warnings']}");
        }
    }

    /**
     * Find or create CarrierArticleMapping for a port and category
     */
    private function findOrCreateMapping(
        ShippingCarrier $carrier,
        Port $port,
        string $category,
        $categoryGroups,
        ?array $wafPortGroupIds,
        array &$stats
    ): ?CarrierArticleMapping {
        // First, try to find article by code pattern
        $article = $this->findArticle($port, $category);

        // If article not found and this is Tenerife, create placeholder articles
        if (!$article && $port->code === 'TFN') {
            $article = $this->createPlaceholderArticle($port, $category);
            if (!$article) {
                $this->command->warn("    ⚠ Failed to create placeholder article for {$port->code} / {$category}. Skipping tariff.");
                $stats['warnings']++;
                return null;
            }
        }

        if (!$article) {
            $this->command->warn("    ⚠ Article not found for {$port->code} / {$category}. Skipping tariff.");
            $stats['warnings']++;
            return null;
        }

        // Check if mapping already exists for this specific port and article
        // For Tenerife, we want separate mappings even if using same article structure
        $existingMapping = CarrierArticleMapping::where('carrier_id', $carrier->id)
            ->where('article_id', $article->id)
            ->whereJsonContains('port_ids', $port->id)
            ->first();

        if ($existingMapping) {
            $stats['mappings_found']++;
            return $existingMapping;
        }

        // For Tenerife, always create separate mappings (don't share with other ports)
        if ($port->code === 'TFN') {
            // Continue to create new mapping below
        } else {
            // For other ports, check if there's a mapping with this article but different port
            $otherMapping = CarrierArticleMapping::where('carrier_id', $carrier->id)
                ->where('article_id', $article->id)
                ->first();

            if ($otherMapping) {
                // Allow sharing mappings (add port_id if not present)
                $portIds = $otherMapping->port_ids ?? [];
                if (!in_array($port->id, $portIds)) {
                    $portIds[] = $port->id;
                    $otherMapping->port_ids = array_values(array_unique($portIds));
                    $otherMapping->save();
                }
                $stats['mappings_found']++;
                return $otherMapping;
            }
        }

        // Mapping doesn't exist, create it following GrimaldiWestAfricaRulesSeeder pattern
        $mappingName = $this->generateMappingName($article, $port);
        $categoryGroupIds = $this->getCategoryGroupIds($category, $categoryGroups);

        $mapping = CarrierArticleMapping::updateOrCreate(
            [
                'carrier_id' => $carrier->id,
                'article_id' => $article->id,
            ],
            [
                'name' => $mappingName,
                'port_ids' => [$port->id],
                'port_group_ids' => $wafPortGroupIds,
                'category_group_ids' => $categoryGroupIds,
                'vehicle_categories' => null, // Mutually exclusive with category_group_ids
                'priority' => 10,
                'effective_from' => now()->subYear(),
                'is_active' => true,
            ]
        );

        if ($mapping->wasRecentlyCreated) {
            $stats['mappings_created']++;
            $this->command->info("    ✓ Created mapping: {$mappingName}");
        }
        
        return $mapping;
    }

    /**
     * Find RobawsArticleCache by article code pattern or description
     */
    private function findArticle(Port $port, string $category): ?RobawsArticleCache
    {
        // Build expected article code: GANR + port code + suffix
        $suffix = $this->categorySuffixes[$category];
        $expectedCode = 'GANR' . $port->code . $suffix;

        // Try exact match first
        $article = RobawsArticleCache::where('article_code', $expectedCode)
            ->where('is_parent_article', true)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereRaw('LOWER(shipping_line) LIKE ?', ['%grimaldi%'])
                  ->orWhere(function ($q2) {
                      // Allow NULL shipping_line only if article code starts with GANR (Grimaldi pattern)
                      $q2->whereNull('shipping_line')
                         ->where('article_code', 'LIKE', 'GANR%')
                         ->whereRaw('LOWER(article_name) NOT LIKE ?', ['%nmt%']);
                  });
            })
            ->first();

        if ($article) {
            return $article;
        }

        // Try with alternative article code pattern (e.g., LFW -> LOM, BJL -> BAN)
        if (isset($this->portCodeToArticlePattern[$port->code])) {
            $altPattern = $this->portCodeToArticlePattern[$port->code];
            $altExpectedCode = 'GANR' . $altPattern . $suffix;
            
            $article = RobawsArticleCache::where('article_code', $altExpectedCode)
                ->where('is_active', true) // Allow non-parent articles too
                ->where(function ($q) {
                    $q->whereRaw('LOWER(shipping_line) LIKE ?', ['%grimaldi%'])
                      ->orWhere(function ($q2) {
                          // Allow NULL shipping_line only if article code starts with GANR (Grimaldi pattern)
                          $q2->whereNull('shipping_line')
                             ->where('article_code', 'LIKE', 'GANR%')
                             ->whereRaw('LOWER(article_name) NOT LIKE ?', ['%nmt%']);
                      });
                })
                ->first();

            if ($article) {
                return $article;
            }
        }

        // Fallback: search by description (destination name + category keywords)
        $commodityType = $this->categoryToCommodityType[$category];
        $portName = strtolower($port->name);
        $portCode = strtolower($port->code);
        
        // Also try alternative article code pattern in fallback
        $altPattern = isset($this->portCodeToArticlePattern[$port->code]) 
            ? strtolower($this->portCodeToArticlePattern[$port->code]) 
            : null;

        $article = RobawsArticleCache::where('is_active', true) // Allow non-parent articles
            ->where(function ($q) {
                $q->whereRaw('LOWER(shipping_line) LIKE ?', ['%grimaldi%'])
                  ->orWhere(function ($q2) {
                      // Allow NULL shipping_line only if article code starts with GANR (Grimaldi pattern)
                      $q2->whereNull('shipping_line')
                         ->where('article_code', 'LIKE', 'GANR%')
                         ->whereRaw('LOWER(article_name) NOT LIKE ?', ['%nmt%']);
                  });
            })
            ->where('commodity_type', $commodityType)
            ->where('article_code', 'LIKE', 'GANR%') // Only Grimaldi article codes
            ->whereRaw('LOWER(article_name) NOT LIKE ?', ['%nmt%']) // Exclude NMT articles
            ->where(function ($q) use ($portName, $portCode, $altPattern, $suffix) {
                $q->whereRaw('LOWER(pod) LIKE ?', ['%' . $portName . '%'])
                  ->orWhereRaw('LOWER(pod) LIKE ?', ['%' . $portCode . '%'])
                  ->orWhereRaw('LOWER(pod_code) LIKE ?', ['%' . $portCode . '%'])
                  ->orWhereRaw('LOWER(article_name) LIKE ?', ['%' . $portName . '%'])
                  ->orWhereRaw('LOWER(article_name) LIKE ?', ['%' . $portCode . '%'])
                  ->orWhereRaw('LOWER(article_code) LIKE ?', ['%ganr' . $portCode . '%'])
                  ->orWhereRaw('LOWER(article_code) LIKE ?', ['%ganr' . $portCode . strtolower($suffix) . '%']);
                
                // Also search by alternative pattern
                if ($altPattern) {
                    $q->orWhereRaw('LOWER(article_code) LIKE ?', ['%ganr' . $altPattern . '%'])
                      ->orWhereRaw('LOWER(article_code) LIKE ?', ['%ganr' . $altPattern . strtolower($suffix) . '%']);
                }
            })
            ->first();

        return $article;
    }

    /**
     * Create placeholder article for ports that don't have articles yet (e.g., Tenerife)
     */
    private function createPlaceholderArticle(Port $port, string $category): ?RobawsArticleCache
    {
        $suffix = $this->categorySuffixes[$category];
        $articleCode = 'GANR' . $port->code . $suffix;
        
        // Check if placeholder already exists
        $existing = RobawsArticleCache::where('article_code', $articleCode)->first();
        if ($existing) {
            return $existing;
        }

        // Get a reference article from Casablanca to copy structure
        $referenceCode = 'GANRCAS' . $suffix;
        $reference = RobawsArticleCache::where('article_code', $referenceCode)->first();
        
        if (!$reference) {
            return null;
        }

        // Create placeholder article based on reference
        $articleName = sprintf(
            'Grimaldi(ANR 1333) %s %s, %s Seafreight',
            $port->name,
            $port->country ?? '',
            $this->categoryToCommodityType[$category]
        );

        $article = RobawsArticleCache::create([
            'robaws_article_id' => 'PLACEHOLDER_' . $articleCode . '_' . time(),
            'article_code' => $articleCode,
            'article_name' => $articleName,
            'description' => $articleName,
            'category' => $reference->category ?? 'seafreight',
            'pod_code' => $port->code,
            'pod' => $port->formatFull() ?? $port->name,
            'commodity_type' => $this->categoryToCommodityType[$category],
            'is_parent_article' => true,
            'is_active' => true,
            'shipping_line' => $reference->shipping_line,
            'transport_mode' => $reference->transport_mode ?? 'SEA',
            'article_type' => $reference->article_type,
            'cost_side' => $reference->cost_side,
            'last_synced_at' => now(),
        ]);

        $this->command->info("    ✓ Created placeholder article: {$articleCode}");
        
        return $article;
    }

    /**
     * Get category group IDs for a category
     */
    private function getCategoryGroupIds(string $category, $categoryGroups): ?array
    {
        return match ($category) {
            'CAR' => $categoryGroups->has('CARS') ? [$categoryGroups['CARS']->id] : null,
            'SMALL_VAN' => $categoryGroups->has('SMALL_VANS') ? [$categoryGroups['SMALL_VANS']->id] : null,
            'BIG_VAN' => $categoryGroups->has('BIG_VANS') ? [$categoryGroups['BIG_VANS']->id] : null,
            'LM' => $this->getLmCargoGroupIds($categoryGroups),
            default => null,
        };
    }

    /**
     * Get LM Cargo category group IDs (try TRUCKS/TRAILERS first, fallback to LM_CARGO)
     */
    private function getLmCargoGroupIds($categoryGroups): ?array
    {
        $lmCargoTrucksId = $categoryGroups->has('LM_CARGO_TRUCKS') ? $categoryGroups['LM_CARGO_TRUCKS']->id : null;
        $lmCargoTrailersId = $categoryGroups->has('LM_CARGO_TRAILERS') ? $categoryGroups['LM_CARGO_TRAILERS']->id : null;
        $lmCargoId = $categoryGroups->has('LM_CARGO') ? $categoryGroups['LM_CARGO']->id : null;

        $ids = array_filter([$lmCargoTrucksId, $lmCargoTrailersId, $lmCargoId]);
        return !empty($ids) ? array_values($ids) : null;
    }

    /**
     * Generate mapping name (following GrimaldiWestAfricaRulesSeeder pattern)
     */
    private function generateMappingName(RobawsArticleCache $article, Port $port): string
    {
        $commodityType = $article->commodity_type ?? '';

        // Build mapping name: "CommodityType PortName (ArticleCode)"
        // Example: "Car Abidjan (GANRABICAR)"
        $name = $commodityType . ' ' . $port->name . ' (' . $article->article_code . ')';

        return $name;
    }

    /**
     * Deactivate older tariffs (effective_from < 2026-01-01)
     */
    private function deactivateOlderTariffs(CarrierArticleMapping $mapping): void
    {
        CarrierPurchaseTariff::where('carrier_article_mapping_id', $mapping->id)
            ->where('effective_from', '<', Carbon::parse(self::EFFECTIVE_DATE)->format('Y-m-d'))
            ->update(['is_active' => false]);
    }

    /**
     * Build tariff data array from normalized values
     * Checks column existence before setting *_unit fields
     */
    private function buildTariffData(
        array $baseFreight,
        array $baf,
        array $ets,
        array $portAdditional,
        array $adminFxe,
        array $thc,
        array $measurementCosts,
        array $congestion,
        array $iccm
    ): array {
        $tariffData = [
            'effective_to' => null,
            'is_active' => true,
            'sort_order' => 0,
            'currency' => 'EUR',
            'base_freight_amount' => $baseFreight['amount'],
            'base_freight_unit' => $baseFreight['unit'],
            'source' => 'import',
            'notes' => 'Grimaldi WAF purchase tariffs from PDF, effective 2026-01-01',
        ];
        
        // Set BAF (check column existence)
        $tariffData['baf_amount'] = $baf['amount'];
        if (\Illuminate\Support\Facades\Schema::hasColumn('carrier_purchase_tariffs', 'baf_unit')) {
            $tariffData['baf_unit'] = $baf['unit'];
        }
        
        // Set ETS
        $tariffData['ets_amount'] = $ets['amount'];
        if (\Illuminate\Support\Facades\Schema::hasColumn('carrier_purchase_tariffs', 'ets_unit')) {
            $tariffData['ets_unit'] = $ets['unit'];
        }
        
        // Set Port Additional
        $tariffData['port_additional_amount'] = $portAdditional['amount'];
        if (\Illuminate\Support\Facades\Schema::hasColumn('carrier_purchase_tariffs', 'port_additional_unit')) {
            $tariffData['port_additional_unit'] = $portAdditional['unit'];
        }
        
        // Set Admin FXE
        $tariffData['admin_fxe_amount'] = $adminFxe['amount'];
        if (\Illuminate\Support\Facades\Schema::hasColumn('carrier_purchase_tariffs', 'admin_fxe_unit')) {
            $tariffData['admin_fxe_unit'] = $adminFxe['unit'];
        }
        
        // Set THC
        $tariffData['thc_amount'] = $thc['amount'];
        if (\Illuminate\Support\Facades\Schema::hasColumn('carrier_purchase_tariffs', 'thc_unit')) {
            $tariffData['thc_unit'] = $thc['unit'];
        }
        
        // Set Measurement Costs
        $tariffData['measurement_costs_amount'] = $measurementCosts['amount'];
        if (\Illuminate\Support\Facades\Schema::hasColumn('carrier_purchase_tariffs', 'measurement_costs_unit')) {
            $tariffData['measurement_costs_unit'] = $measurementCosts['unit'];
        }
        
        // Set Congestion Surcharge
        $tariffData['congestion_surcharge_amount'] = $congestion['amount'];
        if (\Illuminate\Support\Facades\Schema::hasColumn('carrier_purchase_tariffs', 'congestion_surcharge_unit')) {
            $tariffData['congestion_surcharge_unit'] = $congestion['unit'];
        }
        
        // Set ICCM
        $tariffData['iccm_amount'] = $iccm['amount'];
        if (\Illuminate\Support\Facades\Schema::hasColumn('carrier_purchase_tariffs', 'iccm_unit')) {
            $tariffData['iccm_unit'] = $iccm['unit'];
        }
        
        return $tariffData;
    }

    /**
     * Normalize value to amount/unit array
     * Handles: null, numeric, array, string "50/LM"
     * 
     * @param mixed $value
     * @param string $defaultUnit
     * @return array{amount: ?float, unit: ?string}
     */
    private function normalizeValue($value, string $defaultUnit = 'LUMPSUM'): array
    {
        // null → [null, null]
        if ($value === null) {
            return ['amount' => null, 'unit' => null];
        }
        
        // Already an array → return as-is (with validation)
        if (is_array($value) && isset($value['amount'])) {
            return [
                'amount' => is_numeric($value['amount']) ? (float) $value['amount'] : null,
                'unit' => $value['unit'] ?? $defaultUnit,
            ];
        }
        
        // String "50/LM" (defensive parsing)
        if (is_string($value) && preg_match('/^(\d+(?:\.\d+)?)\/(LM|LUMPSUM)$/i', $value, $matches)) {
            return [
                'amount' => (float) $matches[1],
                'unit' => strtoupper($matches[2]),
            ];
        }
        
        // Numeric → [float, defaultUnit]
        if (is_numeric($value)) {
            return [
                'amount' => (float) $value,
                'unit' => $defaultUnit,
            ];
        }
        
        // Fallback
        return ['amount' => null, 'unit' => null];
    }
}

