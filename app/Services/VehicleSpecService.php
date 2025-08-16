<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class VehicleSpecService
{
    /** Return best verified spec row or null (stdClass). */
    public function findVerified(string $make, string $model, int $year, ?string $variant = null, ?string $market = null)
    {
        $q = DB::table('vehicle_specs')
            ->where('is_verified', true)
            ->whereRaw('LOWER(make) = ?', [mb_strtolower($make)])
            ->whereRaw('LOWER(model) = ?', [mb_strtolower($model)])
            ->when($variant, fn($q) => $q->whereRaw('LOWER(variant) = ?', [mb_strtolower($variant)]))
            ->when($market, fn($q) => $q->whereRaw('LOWER(market) = ?', [mb_strtolower($market)]));

        $q->where(function ($q) use ($year) {
            $q->where(function ($q) use ($year) {
                $q->where('year_from', '<=', $year)
                  ->where(function ($q) use ($year) {
                      $q->whereNull('year_to')->orWhere('year_to', '>=', $year);
                  });
            })->orWhere('year', $year);
        });

        return $q->orderByDesc('verified_at')->first();
    }
}
