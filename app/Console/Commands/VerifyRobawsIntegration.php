<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\SimpleRobawsIntegration;
use Illuminate\Console\Command;

class VerifyRobawsIntegration extends Command
{
    protected $signature = 'robaws:verify';
    protected $description = 'Verify the complete Robaws integration status';

    public function handle()
    {
        $this->info('Robaws Integration Status Verification');
        $this->info('=====================================');
        $this->newLine();

        // Overall statistics
        $totalDocs = Document::count();
        $extractedDocs = Document::whereNotNull('extraction_data')->count();
        $robawsDocs = Document::whereNotNull('robaws_quotation_data')->count();
        
        $service = new SimpleRobawsIntegration();
        $readyForExport = $service->getDocumentsReadyForExport()->count();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Documents', $totalDocs],
                ['Documents with Extractions', $extractedDocs],
                ['Documents with Robaws Data', $robawsDocs],
                ['Documents Ready for Export', $readyForExport],
            ]
        );

        $this->newLine();

        // Document by document status
        $documents = Document::latest()->get();
        
        $statusData = [];
        foreach ($documents as $doc) {
            $hasExtraction = $doc->extractions()->exists() ? '✓' : '✗';
            $hasRobaws = !empty($doc->robaws_quotation_data) ? '✓' : '✗';
            $age = $doc->created_at->diffForHumans();
            
            $statusData[] = [
                $doc->id,
                substr($doc->filename, 0, 30) . (strlen($doc->filename) > 30 ? '...' : ''),
                $hasExtraction,
                $hasRobaws,
                $age
            ];
        }

        $this->table(
            ['ID', 'Filename', 'Extraction', 'Robaws', 'Age'],
            $statusData
        );

        $this->newLine();

        if ($robawsDocs == $extractedDocs && $extractedDocs > 0) {
            $this->info('🎉 All extracted documents have Robaws mapping!');
        } else {
            $this->warn('⚠️  Some documents are missing Robaws mapping');
        }

        if ($readyForExport == $robawsDocs) {
            $this->info('📤 All Robaws documents are ready for export!');
        } else {
            $this->warn('⚠️  Some Robaws documents are not ready for export');
        }

        $this->newLine();
        $this->info('Integration verification complete!');
    }
}
