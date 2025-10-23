<?php

namespace Database\Seeders;

use App\Models\Port;
use Illuminate\Database\Seeder;

class PortSeeder extends Seeder
{
    public function run()
    {
        $ports = [
            // European Ports
            ['name' => 'Antwerp', 'code' => 'ANR', 'country' => 'Belgium', 'region' => 'Europe'],
            ['name' => 'Hamburg', 'code' => 'HAM', 'country' => 'Germany', 'region' => 'Europe'],
            ['name' => 'Rotterdam', 'code' => 'RTM', 'country' => 'Netherlands', 'region' => 'Europe'],
            ['name' => 'Bremerhaven', 'code' => 'BRV', 'country' => 'Germany', 'region' => 'Europe'],
            ['name' => 'Zeebrugge', 'code' => 'ZEE', 'country' => 'Belgium', 'region' => 'Europe'],
            ['name' => 'Southampton', 'code' => 'SOU', 'country' => 'United Kingdom', 'region' => 'Europe'],
            ['name' => 'Portsmouth', 'code' => 'POR', 'country' => 'United Kingdom', 'region' => 'Europe'],
            ['name' => 'Flushing', 'code' => 'FLU', 'country' => 'Netherlands', 'region' => 'Europe'],
            ['name' => 'Le Havre', 'code' => 'LEH', 'country' => 'France', 'region' => 'Europe'],
            ['name' => 'Marseille', 'code' => 'MRS', 'country' => 'France', 'region' => 'Europe'],
            ['name' => 'Barcelona', 'code' => 'BCN', 'country' => 'Spain', 'region' => 'Europe'],
            ['name' => 'Valencia', 'code' => 'VLC', 'country' => 'Spain', 'region' => 'Europe'],
            ['name' => 'Genoa', 'code' => 'GOA', 'country' => 'Italy', 'region' => 'Europe'],
            ['name' => 'Livorno', 'code' => 'LIV', 'country' => 'Italy', 'region' => 'Europe'],
            ['name' => 'Brussels', 'code' => 'BRU', 'country' => 'Belgium', 'region' => 'Europe'],

            // African Ports
            ['name' => 'Lagos', 'code' => 'LOS', 'country' => 'Nigeria', 'region' => 'Africa'],
            ['name' => 'Mombasa', 'code' => 'MBA', 'country' => 'Kenya', 'region' => 'Africa'],
            ['name' => 'Durban', 'code' => 'DUR', 'country' => 'South Africa', 'region' => 'Africa'],
            ['name' => 'Cape Town', 'code' => 'CPT', 'country' => 'South Africa', 'region' => 'Africa'],
            ['name' => 'Casablanca', 'code' => 'CAS', 'country' => 'Morocco', 'region' => 'Africa'],
            ['name' => 'Algiers', 'code' => 'ALG', 'country' => 'Algeria', 'region' => 'Africa'],
            ['name' => 'Tunis', 'code' => 'TUN', 'country' => 'Tunisia', 'region' => 'Africa'],
            ['name' => 'Nouakchott', 'code' => 'NKC', 'country' => 'Mauritania', 'region' => 'Africa'],
            ['name' => 'Libreville', 'code' => 'LBV', 'country' => 'Gabon', 'region' => 'Africa'],
            ['name' => 'Freetown', 'code' => 'FNA', 'country' => 'Sierra Leone', 'region' => 'Africa'],
            ['name' => 'Abidjan', 'code' => 'ABJ', 'country' => 'Ivory Coast', 'region' => 'Africa'],
            ['name' => 'Matadi', 'code' => 'MAT', 'country' => 'Congo', 'region' => 'Africa'],
            ['name' => 'Pointe-Noire', 'code' => 'PNR', 'country' => 'Congo', 'region' => 'Africa'],

            // Caribbean Ports
            ['name' => 'Port of Spain', 'code' => 'POS', 'country' => 'Trinidad and Tobago', 'region' => 'Caribbean'],
            ['name' => 'Kingston', 'code' => 'KIN', 'country' => 'Jamaica', 'region' => 'Caribbean'],
            ['name' => 'Bridgetown', 'code' => 'BGI', 'country' => 'Barbados', 'region' => 'Caribbean'],
            ['name' => 'Castries', 'code' => 'SLU', 'country' => 'Saint Lucia', 'region' => 'Caribbean'],
            ['name' => 'Kingstown', 'code' => 'SVD', 'country' => 'Saint Vincent', 'region' => 'Caribbean'],
            ['name' => 'Basseterre', 'code' => 'SKB', 'country' => 'Saint Kitts', 'region' => 'Caribbean'],
            ['name' => 'Roseau', 'code' => 'DOM', 'country' => 'Dominica', 'region' => 'Caribbean'],
            ['name' => 'Willemstad', 'code' => 'CUR', 'country' => 'Curaçao', 'region' => 'Caribbean'],
            ['name' => 'Santo Domingo', 'code' => 'SDQ', 'country' => 'Dominican Republic', 'region' => 'Caribbean'],
            ['name' => 'Georgetown', 'code' => 'GEO', 'country' => 'Guyana', 'region' => 'Caribbean'],
            ['name' => 'Paramaribo', 'code' => 'PBM', 'country' => 'Suriname', 'region' => 'Caribbean'],
            ['name' => 'Cayenne', 'code' => 'CAY', 'country' => 'French Guiana', 'region' => 'Caribbean'],

            // Middle East Ports
            ['name' => 'Jeddah', 'code' => 'JED', 'country' => 'Saudi Arabia', 'region' => 'Middle East'],
            ['name' => 'Dubai', 'code' => 'DXB', 'country' => 'UAE', 'region' => 'Middle East'],
            ['name' => 'Doha', 'code' => 'DOH', 'country' => 'Qatar', 'region' => 'Middle East'],
            ['name' => 'Kuwait', 'code' => 'KWI', 'country' => 'Kuwait', 'region' => 'Middle East'],

            // North American Ports
            ['name' => 'New York', 'code' => 'NYC', 'country' => 'United States', 'region' => 'North America'],
            ['name' => 'Baltimore', 'code' => 'BAL', 'country' => 'United States', 'region' => 'North America'],
            ['name' => 'Charleston', 'code' => 'CHS', 'country' => 'United States', 'region' => 'North America'],
            ['name' => 'Savannah', 'code' => 'SAV', 'country' => 'United States', 'region' => 'North America'],
            ['name' => 'Jacksonville', 'code' => 'JAX', 'country' => 'United States', 'region' => 'North America'],
            ['name' => 'Miami', 'code' => 'MIA', 'country' => 'United States', 'region' => 'North America'],
            ['name' => 'Houston', 'code' => 'HOU', 'country' => 'United States', 'region' => 'North America'],
            ['name' => 'New Orleans', 'code' => 'MSY', 'country' => 'United States', 'region' => 'North America'],
            ['name' => 'Montreal', 'code' => 'YMQ', 'country' => 'Canada', 'region' => 'North America'],
            ['name' => 'Halifax', 'code' => 'HAL', 'country' => 'Canada', 'region' => 'North America'],

            // South American Ports
            ['name' => 'Santos', 'code' => 'SSZ', 'country' => 'Brazil', 'region' => 'South America'],
            ['name' => 'Rio de Janeiro', 'code' => 'RIO', 'country' => 'Brazil', 'region' => 'South America'],
            ['name' => 'Buenos Aires', 'code' => 'BUE', 'country' => 'Argentina', 'region' => 'South America'],
            ['name' => 'Montevideo', 'code' => 'MVD', 'country' => 'Uruguay', 'region' => 'South America'],
            ['name' => 'Valparaiso', 'code' => 'VAP', 'country' => 'Chile', 'region' => 'South America'],
            ['name' => 'Callao', 'code' => 'CAL', 'country' => 'Peru', 'region' => 'South America'],
            ['name' => 'Cartagena', 'code' => 'CTG', 'country' => 'Colombia', 'region' => 'South America'],
            ['name' => 'La Guaira', 'code' => 'LAG', 'country' => 'Venezuela', 'region' => 'South America'],

            // Asian Ports
            ['name' => 'Yokohama', 'code' => 'YOK', 'country' => 'Japan', 'region' => 'Asia'],
        ];
        
        foreach ($ports as $port) {
            Port::updateOrCreate(
                ['code' => $port['code']],
                $port
            );
        }
    }
}