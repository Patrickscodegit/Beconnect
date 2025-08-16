<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class VinWmiService
{
    /** @return array{manufacturer:string,country:string}|null */
    public function country(string $vin): ?array
    {
        $vin = strtoupper(trim($vin));
        if (strlen($vin) < 3) {
            return null;
        }
        
        $wmi = substr($vin, 0, 3);
        $row = DB::table('vin_wmis')->where('wmi', $wmi)->first();
        
        if (!$row) {
            return null;
        }
        
        return [
            'manufacturer' => $row->manufacturer,
            'country' => $row->country
        ];
    }
}
