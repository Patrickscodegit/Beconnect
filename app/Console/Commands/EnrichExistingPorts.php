<?php

namespace App\Console\Commands;

use App\Models\Port;
use App\Services\PortCodeValidationService;
use Illuminate\Console\Command;

class EnrichExistingPorts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ports:enrich-existing
                            {--dry-run : Do not write, only report}
                            {--force-update : Overwrite existing values}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enrich existing ports with UN/LOCODE data from known sources';

    /**
     * UN/LOCODE data mapping (code => [unlocode, port_category, coordinates])
     * Based on existing data sources
     */
    private array $unlocodeData = [
        // West Africa
        'ABJ' => ['unlocode' => 'CIABJ', 'port_category' => 'SEA_PORT', 'coordinates' => '5.3167,-4.0333'],
        'CKY' => ['unlocode' => 'GNCKY', 'port_category' => 'SEA_PORT', 'coordinates' => '9.6412,-13.5784'],
        'COO' => ['unlocode' => 'BJCOO', 'port_category' => 'SEA_PORT', 'coordinates' => '6.3667,2.4333'],
        'DKR' => ['unlocode' => 'SNDKR', 'port_category' => 'SEA_PORT', 'coordinates' => '14.6928,-17.4467'],
        'DLA' => ['unlocode' => 'CMDLA', 'port_category' => 'SEA_PORT', 'coordinates' => '4.0500,9.7000'],
        'LOS' => ['unlocode' => 'NGLOS', 'port_category' => 'SEA_PORT', 'coordinates' => '6.5244,3.3792'],
        'LFW' => ['unlocode' => 'TGLFW', 'port_category' => 'SEA_PORT', 'coordinates' => '6.1319,1.2228'],
        'PNR' => ['unlocode' => 'CGPNR', 'port_category' => 'SEA_PORT', 'coordinates' => '-4.7761,11.8636'],
        
        // East Africa
        'DAR' => ['unlocode' => 'TZDAR', 'port_category' => 'SEA_PORT', 'coordinates' => '-6.7924,39.2083'],
        'MBA' => ['unlocode' => 'KEMBA', 'port_category' => 'SEA_PORT', 'coordinates' => '-4.0437,39.6682'],
        
        // South Africa
        'DUR' => ['unlocode' => 'ZADUR', 'port_category' => 'SEA_PORT', 'coordinates' => '-29.8587,31.0218'],
        'ELS' => ['unlocode' => 'ZAELS', 'port_category' => 'SEA_PORT', 'coordinates' => '-33.0292,27.8546'],
        'PLZ' => ['unlocode' => 'ZAPLZ', 'port_category' => 'SEA_PORT', 'coordinates' => '-33.9608,25.6022'],
        'WVB' => ['unlocode' => 'NAWVB', 'port_category' => 'SEA_PORT', 'coordinates' => '-22.9576,14.5053'],
        
        // Europe
        'ANR' => ['unlocode' => 'BEANR', 'port_category' => 'SEA_PORT', 'coordinates' => '51.2194,4.4025'],
        'ZEE' => ['unlocode' => 'BEZEE', 'port_category' => 'SEA_PORT', 'coordinates' => '51.3308,3.2075'],
        'FLU' => ['unlocode' => 'NLVLI', 'port_category' => 'SEA_PORT', 'coordinates' => '51.4425,3.5736'],
        
        // Additional European ports (common seaports)
        'RTM' => ['unlocode' => 'NLRTM', 'port_category' => 'SEA_PORT'],
        'HAM' => ['unlocode' => 'DEHAM', 'port_category' => 'SEA_PORT'],
        'BRV' => ['unlocode' => 'DEBRE', 'port_category' => 'SEA_PORT'], // Bremerhaven uses Bremen code
        'LEH' => ['unlocode' => 'FRLEH', 'port_category' => 'SEA_PORT'],
        'MRS' => ['unlocode' => 'FRMRS', 'port_category' => 'SEA_PORT'],
        'BCN' => ['unlocode' => 'ESBCN', 'port_category' => 'SEA_PORT'],
        'VLC' => ['unlocode' => 'ESVLC', 'port_category' => 'SEA_PORT'],
        'GOA' => ['unlocode' => 'ITGOA', 'port_category' => 'SEA_PORT'],
        'LIV' => ['unlocode' => 'ITLIV', 'port_category' => 'SEA_PORT'],
        'SOU' => ['unlocode' => 'GBSOU', 'port_category' => 'SEA_PORT'],
        'POR' => ['unlocode' => 'GBPOR', 'port_category' => 'SEA_PORT'],
        
        // Airports
        'BRU' => ['unlocode' => 'BEBRU', 'port_category' => 'AIRPORT'],
        'DXB' => ['unlocode' => 'AEDXB', 'port_category' => 'AIRPORT'],
        'DOH' => ['unlocode' => 'QADOH', 'port_category' => 'AIRPORT'],
        'JED' => ['unlocode' => 'SAJED', 'port_category' => 'AIRPORT'],
        'KWI' => ['unlocode' => 'KWKWI', 'port_category' => 'AIRPORT'],
        
        // Additional common ports
        'CPT' => ['unlocode' => 'ZACPT', 'port_category' => 'SEA_PORT'],
        'CAS' => ['unlocode' => 'MACAS', 'port_category' => 'SEA_PORT'],
        'TUN' => ['unlocode' => 'TNTUN', 'port_category' => 'SEA_PORT'],
        'LBV' => ['unlocode' => 'GALBV', 'port_category' => 'SEA_PORT'],
        'FNA' => ['unlocode' => 'SLFNA', 'port_category' => 'SEA_PORT'],
        
        // North Africa
        'ALG' => ['unlocode' => 'DZALG', 'port_category' => 'SEA_PORT'],
        'NKC' => ['unlocode' => 'MRNKC', 'port_category' => 'SEA_PORT'],
        
        // Central Africa
        'MAT' => ['unlocode' => 'CDMAT', 'port_category' => 'SEA_PORT'],
        
        // United States - Major seaports
        'NYC' => ['unlocode' => 'USNYC', 'port_category' => 'SEA_PORT'],
        'BAL' => ['unlocode' => 'USBAL', 'port_category' => 'SEA_PORT'],
        'CHS' => ['unlocode' => 'USCHS', 'port_category' => 'SEA_PORT'],
        'SAV' => ['unlocode' => 'USSAV', 'port_category' => 'SEA_PORT'],
        'JAX' => ['unlocode' => 'USJAX', 'port_category' => 'SEA_PORT'],
        'MIA' => ['unlocode' => 'USMIA', 'port_category' => 'SEA_PORT'],
        'HOU' => ['unlocode' => 'USHOU', 'port_category' => 'SEA_PORT'],
        'MSY' => ['unlocode' => 'USMSY', 'port_category' => 'SEA_PORT'],
        
        // Canada
        'YMQ' => ['unlocode' => 'CAMTR', 'port_category' => 'SEA_PORT'], // Montreal
        'HAL' => ['unlocode' => 'CAHAL', 'port_category' => 'SEA_PORT'], // Halifax
        
        // South America
        'SSZ' => ['unlocode' => 'BRSSZ', 'port_category' => 'SEA_PORT'], // Santos
        'RIO' => ['unlocode' => 'BRRIO', 'port_category' => 'SEA_PORT'], // Rio de Janeiro
        'BUE' => ['unlocode' => 'ARBUE', 'port_category' => 'SEA_PORT'], // Buenos Aires
        'MVD' => ['unlocode' => 'UYMVD', 'port_category' => 'SEA_PORT'], // Montevideo
        'VAP' => ['unlocode' => 'CLVAP', 'port_category' => 'SEA_PORT'], // Valparaiso
        'CAL' => ['unlocode' => 'PECAL', 'port_category' => 'SEA_PORT'], // Callao
        'CTG' => ['unlocode' => 'COCTG', 'port_category' => 'SEA_PORT'], // Cartagena
        'LAG' => ['unlocode' => 'VELAG', 'port_category' => 'SEA_PORT'], // La Guaira
        
        // Caribbean
        'POS' => ['unlocode' => 'TTPOS', 'port_category' => 'SEA_PORT'], // Port of Spain
        'KIN' => ['unlocode' => 'JMKIN', 'port_category' => 'SEA_PORT'], // Kingston
        'BGI' => ['unlocode' => 'BBBGI', 'port_category' => 'SEA_PORT'], // Bridgetown
        'SLU' => ['unlocode' => 'LCSLU', 'port_category' => 'SEA_PORT'], // Castries
        'SVD' => ['unlocode' => 'VCSVD', 'port_category' => 'SEA_PORT'], // Kingstown
        'SKB' => ['unlocode' => 'KNSKB', 'port_category' => 'SEA_PORT'], // Basseterre
        'DOM' => ['unlocode' => 'DMDOM', 'port_category' => 'SEA_PORT'], // Roseau
        'CUR' => ['unlocode' => 'CWCUR', 'port_category' => 'SEA_PORT'], // Willemstad
        'SDQ' => ['unlocode' => 'DOSDQ', 'port_category' => 'SEA_PORT'], // Santo Domingo
        
        // South America (continued)
        'GEO' => ['unlocode' => 'GYGEO', 'port_category' => 'SEA_PORT'], // Georgetown
        'PBM' => ['unlocode' => 'SRPBM', 'port_category' => 'SEA_PORT'], // Paramaribo
        'CAY' => ['unlocode' => 'GFCAY', 'port_category' => 'SEA_PORT'], // Cayenne
        
        // Asia
        'YOK' => ['unlocode' => 'JPYOK', 'port_category' => 'SEA_PORT'], // Yokohama
    ];

    /**
     * Statistics
     */
    private array $stats = [
        'updated' => 0,
        'skipped' => 0,
        'not_found' => 0,
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔧 Enriching existing ports with UN/LOCODE data...');
        $this->newLine();

        $ports = Port::all();
        $this->info("Processing {$ports->count()} ports");
        $this->newLine();

        foreach ($ports as $port) {
            $this->enrichPort($port);
        }

        $this->displayResults();

        return Command::SUCCESS;
    }

    /**
     * Enrich a single port
     */
    private function enrichPort(Port $port): void
    {
        $code = strtoupper($port->code);
        
        if (!isset($this->unlocodeData[$code])) {
            $this->stats['not_found']++;
            return;
        }

        $data = $this->unlocodeData[$code];
        $updates = [];
        $forceUpdate = $this->option('force-update');

        // Update unlocode
        if ($forceUpdate || empty($port->unlocode)) {
            $updates['unlocode'] = $data['unlocode'];
        }

        // Update port_category
        if ($forceUpdate || ($port->port_category === 'UNKNOWN' || empty($port->port_category))) {
            $updates['port_category'] = $data['port_category'];
        }

        // Ensure country_code is set (extract from unlocode if missing)
        if (empty($port->country_code) && isset($data['unlocode'])) {
            $countryCode = strtoupper(substr($data['unlocode'], 0, 2));
            if (ctype_alpha($countryCode) && strlen($countryCode) === 2) {
                $updates['country_code'] = $countryCode;
            }
        }

        if (empty($updates)) {
            $this->stats['skipped']++;
            return;
        }

        if ($this->option('dry-run')) {
            $this->line("  [DRY RUN] Would update: {$port->name} ({$port->code})");
            $this->line("    Fields: " . implode(', ', array_keys($updates)));
            $this->stats['updated']++;
            return;
        }

        $port->update($updates);
        $this->line("  ✅ Updated: {$port->name} ({$port->code})");
        $this->stats['updated']++;
    }

    /**
     * Display results
     */
    private function displayResults(): void
    {
        $this->newLine();
        $this->info('📊 Enrichment Results:');
        $this->line("   Updated: {$this->stats['updated']}");
        $this->line("   Skipped: {$this->stats['skipped']}");
        $this->line("   Not found in data: {$this->stats['not_found']}");
    }
}

