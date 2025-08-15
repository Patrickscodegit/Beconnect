<?php

namespace App\Console\Commands;

use App\Jobs\PreprocessJob;
use App\Models\Document;
use App\Models\Intake;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestPipelineCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pipeline:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the freight-forwarding extraction pipeline';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Testing freight-forwarding extraction pipeline...');
        
        // Create a test intake
        $intake = Intake::create([
            'status' => 'uploaded',
            'source' => 'test_pipeline',
            'priority' => 'high',
            'notes' => ['pipeline_test_started_at_' . now()]
        ]);
        
        $this->info("Created test intake ID: {$intake->id}");
        
        // Create mock documents
        $mockPdfContent = "%PDF-1.4\n1 0 obj\n<<\n/Type /Catalog\n>>\nendobj\nxref\ntrailer\n<<\n/Root 1 0 R\n>>\n%%EOF";
        Storage::disk('s3')->put("test-documents/sample-invoice.pdf", $mockPdfContent);
        Storage::disk('s3')->put("test-documents/sample-bill-of-lading.pdf", $mockPdfContent);
        
        // Create document records
        $documents = [
            [
                'filename' => 'sample-invoice.pdf',
                'file_path' => 'test-documents/sample-invoice.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => strlen($mockPdfContent)
            ],
            [
                'filename' => 'sample-bill-of-lading.pdf', 
                'file_path' => 'test-documents/sample-bill-of-lading.pdf',
                'mime_type' => 'application/pdf',
                'file_size' => strlen($mockPdfContent)
            ]
        ];
        
        foreach ($documents as $docData) {
            Document::create(array_merge($docData, ['intake_id' => $intake->id]));
            $this->info("Created document: {$docData['filename']}");
        }
        
        // Dispatch the first job to start the pipeline
        PreprocessJob::dispatch($intake->id)->onQueue('default');
        
        $this->info('Pipeline test initiated!');
        $this->info("Intake ID: {$intake->id}");
        $this->info('Monitor the pipeline with: php artisan queue:work');
        $this->info('Check intake status with: php artisan tinker --execute="echo App\Models\Intake::find(' . $intake->id . ')"');
        
        return 0;
    }
}
