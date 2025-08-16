<?php

namespace App\Services;

use App\Models\Intake;
use App\Models\Vehicle as VehicleModel;
use App\Models\Party as PartyModel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class RuleEngine
{
    public function __construct(
        protected VinWmiService $wmi,
        protected VehicleSpecService $specs,
    ) {}

    /** @return array{all_verified:bool,notes:array} */
    public function apply(Intake $intake, array $raw): array
    {
        // 1) Parties (replace current for intake)
        $this->persistParties($intake, Arr::get($raw, 'parties', []));

        // 2) Vehicles normalize + enrich verified-only
        $normalized = [];
        $seen = [];

        foreach ((array) Arr::get($raw, 'vehicles', []) as $v) {
            $nv = $this->normalizeVehicle($v);

            // VIN checksum + WMI -> country (verified)
            if ($nv['vin'] && $this->validVin($nv['vin'])) {
                if ($info = $this->wmi->country($nv['vin'])) {
                    $nv['country_of_manufacture'] = $info['country'];
                    $nv['country_verified'] = true;
                }
            }

            // Verified spec match by make/model/year
            $spec = null;
            if ($nv['make'] && $nv['model'] && $nv['year']) {
                $spec = $this->specs->findVerified($nv['make'], $nv['model'], (int) $nv['year'], $nv['variant'] ?? null, $nv['market'] ?? null);
            }
            if ($spec) {
                $nv['spec_id']         = $spec->id;
                $nv['length_m']        = $nv['length_m']     ?: $spec->length_m;
                $nv['width_m']         = $nv['width_m']      ?: $spec->width_m;
                $nv['height_m']        = $nv['height_m']     ?: $spec->height_m;
                $nv['wheelbase_m']     = $nv['wheelbase_m']  ?: $spec->wheelbase_m;
                $nv['weight_kg']       = $nv['weight_kg']    ?: $spec->weight_kg;
                $nv['engine_cc']       = $nv['engine_cc']    ?: $spec->engine_cc;
                $nv['fuel_type']       = $nv['fuel_type']    ?: $spec->fuel_type;
                $nv['powertrain_type'] = $nv['powertrain_type'] ?: $spec->powertrain_type;

                $notes = [];
                if (!empty($spec->width_basis))  $notes['width_basis']  = $spec->width_basis;
                if (!empty($spec->weight_type))  $notes['weight_type']  = $spec->weight_type;
                if ($notes) $nv['measurement_notes'] = $notes;

                if (empty($nv['country_of_manufacture']) && !empty($spec->country_of_manufacture)) {
                    $nv['country_of_manufacture'] = $spec->country_of_manufacture; // not auto-verified
                }
            } else {
                $nv['notes'][] = 'specs_missing';
            }

            // CBM only if L/W/H all present
            if ($nv['length_m'] && $nv['width_m'] && $nv['height_m']) {
                $nv['cbm'] = round((float)$nv['length_m'] * (float)$nv['width_m'] * (float)$nv['height_m'], 3);
            }

            // Dedup by (vin|plate|make|model|year)
            $uk = strtoupper(implode('|', [
                $nv['vin'] ?? '', $nv['plate'] ?? '', $nv['make'] ?? '', $nv['model'] ?? '', $nv['year'] ?? ''
            ]));
            if (isset($seen[$uk])) continue;
            $seen[$uk] = true;

            $normalized[] = $nv;
        }

        // 3) Persist vehicles atomically (replace set)
        DB::transaction(function () use ($intake, $normalized) {
            VehicleModel::where('intake_id', $intake->id)->delete();
            foreach ($normalized as $nv) {
                VehicleModel::create([
                    'intake_id' => $intake->id,
                    'spec_id'   => $nv['spec_id'] ?? null,
                    'vin'       => $nv['vin'] ?? null,
                    'plate'     => $nv['plate'] ?? null,
                    'make'      => $nv['make'] ?? null,
                    'model'     => $nv['model'] ?? null,
                    'year'      => $nv['year'] ?? null,
                    'length_m'  => $nv['length_m'] ?? null,
                    'width_m'   => $nv['width_m'] ?? null,
                    'height_m'  => $nv['height_m'] ?? null,
                    'wheelbase_m'=> $nv['wheelbase_m'] ?? null,
                    'cbm'       => $nv['cbm'] ?? null,
                    'weight_kg' => $nv['weight_kg'] ?? null,
                    'engine_cc' => $nv['engine_cc'] ?? null,
                    'fuel_type' => $nv['fuel_type'] ?? null,
                    'powertrain_type' => $nv['powertrain_type'] ?? null,
                    'country_of_manufacture' => $nv['country_of_manufacture'] ?? null,
                    'country_verified' => (bool) ($nv['country_verified'] ?? false),
                    'measurement_notes' => $nv['measurement_notes'] ?? null,
                    'notes' => $nv['notes'] ?? [],
                ]);
            }
        });

        // 4) Gate for Robaws
        $allVerified = collect($normalized)->every(
            fn ($v) => !empty($v['spec_id']) && !empty($v['country_verified'])
        );

        // 5) Notes (POR)
        $notes = $intake->notes ?? [];
        if (!empty($raw['por'])) $notes[] = 'por_resolved:' . $raw['por'];
        $intake->update(['notes' => $notes]);

        return ['all_verified' => $allVerified, 'notes' => $notes];
    }

    /** ---------- helpers ---------- */

    protected function persistParties(Intake $intake, array $parties): void
    {
        PartyModel::where('intake_id', $intake->id)->delete();
        foreach (['customer','shipper','consignee','notify','forwarder','forwarder_pol','forwarder_pod'] as $role) {
            $p = $parties[$role] ?? null;
            if (!$p || !is_array($p)) continue;
            PartyModel::create([
                'intake_id' => $intake->id, 'role' => $role,
                'name' => $p['name'] ?? null, 'street' => $p['street'] ?? null,
                'city' => $p['city'] ?? null, 'postal_code' => $p['postal_code'] ?? null,
                'country' => $p['country'] ?? null,
            ]);
        }
    }

    protected function normalizeVehicle(array $v): array
    {
        $dims = is_array($v['dims_m'] ?? null) ? $v['dims_m'] : [];
        return [
            'spec_id'   => null,
            'vin'       => $v['vin'] ?? null,
            'plate'     => $v['plate'] ?? null,
            'make'      => $v['make'] ?? null,
            'model'     => $v['model'] ?? null,
            'year'      => $v['year'] ?? null,
            'variant'   => $v['variant'] ?? null,
            'market'    => $v['market'] ?? null,
            'length_m'  => $dims['L'] ?? null,
            'width_m'   => $dims['W'] ?? null,
            'height_m'  => $dims['H'] ?? null,
            'wheelbase_m' => $dims['wheelbase'] ?? null,
            'cbm'       => $v['cbm'] ?? null,
            'weight_kg' => $v['weight_kg'] ?? null,
            'engine_cc' => $v['engine_cc'] ?? null,
            'fuel_type' => $v['fuel_type'] ?? null,
            'powertrain_type' => $v['powertrain_type'] ?? null,
            'country_of_manufacture' => Arr::get($v, 'country_of_manufacture.value'),
            'country_verified'       => (bool) Arr::get($v, 'country_of_manufacture.verified', false),
            'measurement_notes' => $v['measurement_notes'] ?? null,
            'notes' => is_array($v['notes'] ?? null) ? $v['notes'] : [],
        ];
    }

    /** ISO 3779 VIN checksum (adequate for most WMIs). */
    protected function validVin(string $vin): bool
    {
        $vin = strtoupper(trim($vin));
        if (strlen($vin) !== 17) return false;
        if (preg_match('/[IOQ]/', $vin)) return false;

        $trans = ['A'=>1,'B'=>2,'C'=>3,'D'=>4,'E'=>5,'F'=>6,'G'=>7,'H'=>8,'J'=>1,'K'=>2,'L'=>3,'M'=>4,'N'=>5,'P'=>7,'R'=>9,'S'=>2,'T'=>3,'U'=>4,'V'=>5,'W'=>6,'X'=>7,'Y'=>8,'Z'=>9];
        $weights = [8,7,6,5,4,3,2,10,0,9,8,7,6,5,4,3,2];

        $sum = 0;
        for ($i=0; $i<17; $i++) {
            $c = $vin[$i];
            $v = is_numeric($c) ? (int)$c : ($trans[$c] ?? 0);
            $sum += $v * $weights[$i];
        }

        $check = $sum % 11;
        $expected = $check === 10 ? 'X' : (string) $check;
        return $vin[8] === $expected;
    }
}
