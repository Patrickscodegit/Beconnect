<?php

namespace App\Console\Commands;

use App\Models\CarrierPurchaseTariff;
use App\Models\CarrierArticleMapping;
use App\Models\ShippingCarrier;
use Illuminate\Console\Command;

class CheckGrimaldiTariffs extends Command
{
    protected $signature = 'grimaldi:check-tariffs';
    protected $description = 'Check how many Grimaldi purchase tariffs exist';

    public function handle()
    {
        $carrier = ShippingCarrier::where('code', 'GRIMALDI')->first();
        if (!$carrier) {
            $this->error('Grimaldi carrier not found');
            return 1;
        }

        $mappings = CarrierArticleMapping::where('carrier_id', $carrier->id)
            ->with(['purchaseTariffs' => function($q) {
                $q->where('is_active', true)
                  ->where('effective_from', '>=', '2026-01-01');
            }])
            ->get();

        $totalTariffs = 0;
        $portsWithTariffs = [];

        foreach ($mappings as $mapping) {
            $tariffCount = $mapping->purchaseTariffs->count();
            if ($tariffCount > 0) {
                $totalTariffs += $tariffCount;
                // Get port from port_ids
                $portIds = $mapping->port_ids ?? [];
                if (!empty($portIds)) {
                    $port = \App\Models\Port::find($portIds[0]);
                    if ($port) {
                        if (!isset($portsWithTariffs[$port->code])) {
                            $portsWithTariffs[$port->code] = 0;
                        }
                        $portsWithTariffs[$port->code] += $tariffCount;
                    }
                }
            }
        }

        $this->info("Total active tariffs (2026-01-01+): {$totalTariffs}");
        $this->info("Total mappings: " . $mappings->count());
        $this->info("Ports with tariffs: " . count($portsWithTariffs));
        $this->newLine();
        $this->info("Ports breakdown:");
        foreach ($portsWithTariffs as $code => $count) {
            $this->line("  {$code}: {$count} tariffs");
        }

        return 0;
    }
}

