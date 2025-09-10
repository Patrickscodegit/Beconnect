<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Extraction\Strategies\PatternExtractor;

class DebugPatternExtraction extends Command
{
    protected $signature = 'debug:patterns';
    protected $description = 'Debug pattern extraction directly';

    public function handle()
    {
        $this->info('=== Testing Pattern Extraction Directly ===');
        $this->newLine();

        $content = "Goede middag

Kunnen jullie me aanbieden voor Ro-Ro transport van een heftruck van Antwerpen naar MOMBASA

Details heftruck: 

Jungheftruck TFG435s
L390 cm
B230 cm
H310cm
3500KG

Hoor graag

Mvgr
Nancy";

        $this->info('1. Test Content:');
        $this->line($content);
        $this->newLine();

        try {
            $patternExtractor = app(PatternExtractor::class);
            $result = $patternExtractor->extract($content);
            
            $this->info('2. Extraction Result:');
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
            
            if (isset($result['vehicle']['dimensions'])) {
                $dims = $result['vehicle']['dimensions'];
                $this->info('✅ Dimensions found:');
                $this->line('  Length: ' . ($dims['length_m'] ?? 'N/A'));
                $this->line('  Width: ' . ($dims['width_m'] ?? 'N/A'));
                $this->line('  Height: ' . ($dims['height_m'] ?? 'N/A'));
            } else {
                $this->error('❌ No dimensions found');
            }

        } catch (\Exception $e) {
            $this->error('❌ Exception: ' . $e->getMessage());
            $this->line('Trace: ' . $e->getTraceAsString());
        }

        return 0;
    }
}
