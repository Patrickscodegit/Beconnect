<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Intake;
use App\Jobs\ProcessEmailDocument;
use App\Services\EmailParserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestEmailProcessing extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:email-processing {file?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test email processing functionality';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Testing Email Processing System');
        $this->line('=====================================');

        // Test EmailParserService directly
        $this->info('1. Testing EmailParserService...');
        $emailParser = app(EmailParserService::class);
        
        // Create a simple test .eml content
        $testEmlContent = "Subject: Shipping Quote - Mercedes Sprinter from Antwerp to Tema
From: john@shipping.com
To: quotes@bconnect.com
Date: " . now()->format('r') . "
Content-Type: text/plain

Hello,

I need a shipping quote for:
- Vehicle: Mercedes Sprinter Van 2023
- Route: Antwerp, Belgium to Tema, Ghana
- Service: RoRo Shipping
- Price: €3,500
- Contact: John Smith, +32 123 456 789

Please confirm availability and timing.

Best regards,
John Smith
ABC Shipping Solutions
";

        // Save test file
        $testFilePath = 'test-email.eml';
        Storage::put($testFilePath, $testEmlContent);
        
        // Create test intake and document
        $intake = Intake::create([
            'intake_name' => 'Test Email Processing - ' . now()->format('Y-m-d H:i:s'),
            'description' => 'Testing email processing functionality',
            'status' => 'processing',
        ]);

        $document = Document::create([
            'intake_id' => $intake->id,
            'filename' => 'test-shipping-email.eml',
            'file_path' => $testFilePath,
            'file_size' => strlen($testEmlContent),
            'mime_type' => 'message/rfc822',
            'storage_disk' => 'local',
            'upload_completed' => true,
        ]);

        $this->info("Created test document: {$document->id}");

        try {
            // Test email parsing
            $this->info('2. Testing email parsing...');
            $emailData = $emailParser->parseEmlFile($document);
            
            $this->line('Email parsed successfully:');
            $this->line("  Subject: " . ($emailData['subject'] ?? 'N/A'));
            $this->line("  From: " . ($emailData['from'] ?? 'N/A'));
            $this->line("  Content Length: " . strlen($emailData['body'] ?? ''));

            // Test shipping content extraction
            $this->info('3. Testing shipping content extraction...');
            $shippingContent = $emailParser->extractShippingContent($emailData);
            $this->line("Shipping content extracted (" . strlen($shippingContent) . " chars):");
            $this->line(substr($shippingContent, 0, 200) . '...');

            // Test full processing job
            $this->info('4. Testing ProcessEmailDocument job...');
            dispatch_sync(new ProcessEmailDocument($document));

            // Check results
            $document->refresh();
            $extractions = $document->extractions;
            
            $this->info('5. Processing Results:');
            $this->line("  Extractions created: " . $extractions->count());
            
            if ($extractions->count() > 0) {
                $extraction = $extractions->first();
                $this->line("  Status: " . $extraction->status);
                $this->line("  Confidence: " . $extraction->confidence);
                $this->line("  Service: " . $extraction->service_used);
                $this->line("  Analysis Type: " . $extraction->analysis_type);
                
                if ($extraction->extracted_data) {
                    $this->line("  Extracted Data Structure:");
                    $data = $extraction->extracted_data;
                    
                    if (isset($data['contact']['name'])) {
                        $this->line("    Contact Name: " . $data['contact']['name']);
                    }
                    if (isset($data['contact']['phone'])) {
                        $this->line("    Contact Phone: " . $data['contact']['phone']);
                    }
                    if (isset($data['vehicle']['make_model'])) {
                        $this->line("    Vehicle: " . $data['vehicle']['make_model']);
                    }
                    if (isset($data['shipment']['origin'])) {
                        $this->line("    Origin: " . $data['shipment']['origin']);
                    }
                    if (isset($data['shipment']['destination'])) {
                        $this->line("    Destination: " . $data['shipment']['destination']);
                    }
                }
            }

            $this->info('✅ Email processing test completed successfully!');

        } catch (\Exception $e) {
            $this->error('❌ Email processing test failed:');
            $this->error($e->getMessage());
            $this->line($e->getTraceAsString());
        } finally {
            // Cleanup
            Storage::delete($testFilePath);
            $document->delete();
            $intake->delete();
            $this->info('🧹 Test cleanup completed');
        }
    }
}
