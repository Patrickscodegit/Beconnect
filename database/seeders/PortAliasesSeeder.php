<?php

namespace Database\Seeders;

use App\Models\Port;
use App\Models\PortAlias;
use App\Services\PortCodeMapper;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class PortAliasesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * Migrates hardcoded mappings from PortCodeMapper and RobawsMapper to database aliases.
     * Idempotent: upserts by alias_normalized.
     */
    public function run(): void
    {
        $this->command->info('Starting port aliases seeding...');

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        // 1. Migrate entries from PortCodeMapper::$cityToCode
        $this->command->info('Migrating PortCodeMapper::$cityToCode...');
        $cityToCode = $this->getCityToCodeMapping();
        
        foreach ($cityToCode as $city => $code) {
            $port = Port::findByCodeInsensitive($code);
            if (!$port) {
                $this->command->warn("Port with code '{$code}' not found, skipping city alias '{$city}'");
                $skipped++;
                continue;
            }

            $alias = PortAlias::updateOrCreate(
                ['alias_normalized' => PortAlias::normalizeAlias($city)],
                [
                    'port_id' => $port->id,
                    'alias' => $city,
                    'alias_type' => 'name_variant',
                    'is_active' => true,
                ]
            );

            if ($alias->wasRecentlyCreated) {
                $inserted++;
            } else {
                $updated++;
            }
        }

        // 2. Migrate variants from RobawsMapper::normalizePortNames()
        $this->command->info('Migrating RobawsMapper::normalizePortNames() variants...');
        $normalizations = [
            'djeddah' => 'Jeddah',
            'djidda' => 'Jeddah',
            'jiddah' => 'Jeddah',
        ];

        foreach ($normalizations as $variant => $canonical) {
            // Try to resolve canonical via PortResolutionService or direct lookup
            $port = Port::findByNameInsensitive($canonical);
            if (!$port) {
                // Try by code if Jeddah exists
                if ($canonical === 'Jeddah') {
                    $port = Port::findByCodeInsensitive('JED');
                }
            }

            if (!$port) {
                $this->command->warn("Port '{$canonical}' not found, skipping variant '{$variant}'");
                $skipped++;
                continue;
            }

            $alias = PortAlias::updateOrCreate(
                ['alias_normalized' => PortAlias::normalizeAlias($variant)],
                [
                    'port_id' => $port->id,
                    'alias' => $variant,
                    'alias_type' => 'typo',
                    'is_active' => true,
                ]
            );

            if ($alias->wasRecentlyCreated) {
                $inserted++;
            } else {
                $updated++;
            }
        }

        // 3. Backfill from ports.shipping_codes JSON
        $this->command->info('Backfilling from ports.shipping_codes JSON...');
        $ports = Port::whereNotNull('shipping_codes')->get();
        
        foreach ($ports as $port) {
            $shippingCodes = $port->shipping_codes ?? [];
            if (!is_array($shippingCodes)) {
                continue;
            }

            foreach ($shippingCodes as $altCode) {
                if (empty($altCode) || $altCode === $port->code || $altCode === $port->name) {
                    continue; // Skip if same as canonical
                }

                $aliasType = preg_match('/^[A-Z0-9]{2,6}$/i', $altCode) ? 'code_variant' : 'name_variant';

                $alias = PortAlias::updateOrCreate(
                    ['alias_normalized' => PortAlias::normalizeAlias($altCode)],
                    [
                        'port_id' => $port->id,
                        'alias' => $altCode,
                        'alias_type' => $aliasType,
                        'is_active' => true,
                    ]
                );

                if ($alias->wasRecentlyCreated) {
                    $inserted++;
                } else {
                    $updated++;
                }
            }
        }

        // 4. Add common known variants (examples only; keep minimal)
        $this->command->info('Adding common known variants...');
        $commonVariants = [
            'Malaba' => 'Malabo', // Only if Malabo exists
        ];

        foreach ($commonVariants as $variant => $canonical) {
            $port = Port::findByNameInsensitive($canonical);
            if (!$port) {
                $this->command->warn("Port '{$canonical}' not found, skipping variant '{$variant}'");
                $skipped++;
                continue;
            }

            $alias = PortAlias::updateOrCreate(
                ['alias_normalized' => PortAlias::normalizeAlias($variant)],
                [
                    'port_id' => $port->id,
                    'alias' => $variant,
                    'alias_type' => 'typo',
                    'is_active' => true,
                ]
            );

            if ($alias->wasRecentlyCreated) {
                $inserted++;
            } else {
                $updated++;
            }
        }

        $this->command->info("Port aliases seeding completed!");
        $this->command->info("  Inserted: {$inserted}");
        $this->command->info("  Updated: {$updated}");
        $this->command->info("  Skipped: {$skipped}");
    }

    /**
     * Get city to code mapping from PortCodeMapper
     * Using reflection to access protected property
     */
    private function getCityToCodeMapping(): array
    {
        try {
            $reflection = new \ReflectionClass(PortCodeMapper::class);
            $property = $reflection->getProperty('cityToCode');
            $property->setAccessible(true);
            return $property->getValue();
        } catch (\Exception $e) {
            Log::warning('Failed to get cityToCode from PortCodeMapper', ['error' => $e->getMessage()]);
            return [];
        }
    }
}

