<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Intake;
use Illuminate\Console\Command;
use App\Jobs\ExtractDocumentData;

class TestExtraction extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:extraction';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the document extraction pipeline';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Testing document extraction pipeline...');

        // Create test intake
        $intake = Intake::create([
            'name' => 'Test Extraction User',
            'email' => 'test@example.com',
            'phone' => '555-0123'
        ]);

        $this->info("Created test intake: {$intake->id}");

        // Create test document
        $document = Document::create([
            'intake_id' => $intake->id,
            'filename' => 'test-document.pdf',
            'file_path' => 'test/sample.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 12345
        ]);

        $this->info("Created test document: {$document->id}");

        // Check if extraction job was dispatched
        $this->info('Document created - extraction job should be dispatched automatically.');
        
        // Manually dispatch job for testing
        ExtractDocumentData::dispatch($document);
        $this->info('Manually dispatched extraction job for testing.');

        return Command::SUCCESS;
    }
}
