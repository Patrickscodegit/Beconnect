<?php

namespace App\Console\Commands;

use App\Models\RobawsSupplierCache;
use App\Models\ShippingCarrier;
use Illuminate\Console\Command;

class LinkCarriersToSuppliers extends Command
{
    protected $signature = 'carriers:link-to-suppliers
                          {--carrier= : Specific carrier to link (grimaldi or sallaum)}
                          {--dry-run : Show what would be done without making changes}';

    protected $description = 'Link shipping carriers to Robaws suppliers for Grimaldi and Sallaum';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $carrierFilter = $this->option('carrier');

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $carriersToLink = [];

        // Define carriers to link
        if (!$carrierFilter || strtolower($carrierFilter) === 'grimaldi') {
            $carriersToLink[] = [
                'name' => 'Grimaldi GNET',
                'code' => 'GRIMALDI',
                'website_url' => 'https://www.grimaldi.com/schedules',
                'service_types' => ['RORO'],
                'specialization' => [
                    'africa_routes' => true,
                    'vehicle_transportation' => true
                ],
                'service_level' => 'Standard',
                'supplier_name_pattern' => 'grimaldi',
                'supplier_exclude_pattern' => 'argentina',
            ];
        }

        if (!$carrierFilter || strtolower($carrierFilter) === 'sallaum') {
            $carriersToLink[] = [
                'name' => 'Sallaum Lines',
                'code' => 'SALLAUM',
                'website_url' => 'https://www.sallaumlines.com/schedules',
                'service_types' => ['RORO'],
                'specialization' => [
                    'africa_routes' => true,
                    'esg_focus' => true
                ],
                'service_level' => 'Standard',
                'supplier_name_pattern' => 'sallaum',
                'supplier_exclude_pattern' => null,
            ];
        }

        $linked = 0;
        $created = 0;
        $skipped = 0;

        foreach ($carriersToLink as $carrierData) {
            $this->info("Processing: {$carrierData['name']} ({$carrierData['code']})");
            
            // Find or create carrier
            $carrier = ShippingCarrier::where('code', $carrierData['code'])->first();
            
            if (!$carrier) {
                if ($dryRun) {
                    $this->line("  → Would create carrier: {$carrierData['name']}");
                } else {
                    $carrier = ShippingCarrier::create([
                        'name' => $carrierData['name'],
                        'code' => $carrierData['code'],
                        'website_url' => $carrierData['website_url'],
                        'api_endpoint' => $carrierData['website_url'] . '/api',
                        'service_types' => $carrierData['service_types'],
                        'specialization' => $carrierData['specialization'],
                        'service_level' => $carrierData['service_level'],
                        'is_active' => true,
                    ]);
                    $this->line("  ✅ Created carrier: {$carrier->name}");
                    $created++;
                }
            } else {
                $this->line("  ℹ️  Carrier already exists: {$carrier->name}");
            }

            // Find supplier
            $query = RobawsSupplierCache::whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($carrierData['supplier_name_pattern']) . '%']);
            
            if ($carrierData['supplier_exclude_pattern']) {
                $query->whereRaw('LOWER(name) NOT LIKE ?', ['%' . strtolower($carrierData['supplier_exclude_pattern']) . '%']);
            }

            // Prefer shipping_line type if available
            $supplier = $query->where('supplier_type', 'shipping_line')->first();
            if (!$supplier) {
                $supplier = $query->first();
            }

            if (!$supplier) {
                $this->warn("  ⚠️  No supplier found matching pattern: {$carrierData['supplier_name_pattern']}");
                $skipped++;
                continue;
            }

            $this->line("  📦 Found supplier: {$supplier->name} (ID: {$supplier->robaws_supplier_id})");

            // Check if already linked (only if carrier exists)
            if ($carrier && $carrier->robaws_supplier_id === $supplier->robaws_supplier_id) {
                $this->line("  ✓ Already linked to supplier");
                continue;
            }

            // Link carrier to supplier
            if ($dryRun) {
                $this->line("  → Would link carrier to supplier: {$supplier->name}");
            } else {
                if (!$carrier) {
                    $this->error("  ❌ Carrier was not created, cannot link");
                    $skipped++;
                    continue;
                }
                $carrier->update(['robaws_supplier_id' => $supplier->robaws_supplier_id]);
                $this->line("  ✅ Linked carrier to supplier: {$supplier->name}");
                $linked++;
            }
        }

        $this->newLine();
        $this->info('Summary:');
        $this->table(
            ['Action', 'Count'],
            [
                ['Created', $created],
                ['Linked', $linked],
                ['Skipped', $skipped],
            ]
        );

        return Command::SUCCESS;
    }
}
