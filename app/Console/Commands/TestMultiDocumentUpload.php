<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Quotation;
use App\Services\MultiDocumentUploadService;
use App\Services\RobawsClient;
use Illuminate\Console\Command;

class TestMultiDocumentUpload extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:multi-document-upload {--quotation-id= : Specific Robaws quotation ID to test} {--dry-run : Show what would be uploaded without actually uploading}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test multi-document upload functionality to Robaws';

    /**
     * Execute the console command.
     */
    public function handle(MultiDocumentUploadService $uploadService, RobawsClient $robawsClient): int
    {
        $this->info('🧪 Testing Multi-Document Upload to Robaws');
        $this->newLine();

        // Test 1: API Connection
        $this->info('1. Testing Robaws API connection...');
        try {
            $connectionTest = $robawsClient->testConnection();
            if ($connectionTest['success']) {
                $this->info('   ✅ API connection successful');
            } else {
                $this->error('   ❌ API connection failed: ' . $connectionTest['message']);
                return 1;
            }
        } catch (\Exception $e) {
            $this->error('   ❌ API connection error: ' . $e->getMessage());
            return 1;
        }
        $this->newLine();

        // Test 2: Find quotations with documents
        $this->info('2. Finding quotations with documents...');
        
        $quotationId = $this->option('quotation-id');
        if ($quotationId) {
            $quotation = Quotation::where('robaws_id', $quotationId)->first();
            $quotations = $quotation ? collect([$quotation]) : collect();
        } else {
            $quotations = Quotation::whereNotNull('robaws_id')
                ->whereHas('document')
                ->latest()
                ->take(3)
                ->get();
        }

        if ($quotations->isEmpty()) {
            $this->error('   ❌ No quotations with documents found');
            return 1;
        }

        $this->info("   ✅ Found {$quotations->count()} quotations with documents");
        $this->newLine();

        // Test 3: Analyze documents for each quotation
        foreach ($quotations as $quotation) {
            $this->info("3. Analyzing quotation {$quotation->robaws_id}...");
            
            try {
                $status = $uploadService->getUploadStatus($quotation);
                
                $this->table(['Metric', 'Count'], [
                    ['Total Documents', $status['total_documents']],
                    ['Uploaded', $status['uploaded']],
                    ['Failed', $status['failed']],
                    ['Pending', $status['pending']]
                ]);

                if (!empty($status['documents'])) {
                    $this->info('   Documents:');
                    foreach ($status['documents'] as $doc) {
                        $status_icon = match($doc['upload_status']) {
                            'uploaded' => '✅',
                            'failed' => '❌',
                            default => '⏳'
                        };
                        
                        $this->line("   {$status_icon} {$doc['filename']} ({$doc['mime_type']}, " . 
                                   number_format($doc['file_size'] / 1024, 1) . " KB)");
                        
                        if ($doc['upload_status'] === 'failed' && $doc['error']) {
                            $this->line("      Error: {$doc['error']}");
                        }
                    }
                }

                $this->newLine();

                // Test 4: Upload documents (if not dry-run)
                if (!$this->option('dry-run') && $status['pending'] > 0) {
                    if ($this->confirm("Upload {$status['pending']} pending documents for quotation {$quotation->robaws_id}?")) {
                        $this->info('4. Uploading documents...');
                        
                        $uploadResults = $uploadService->uploadQuotationDocuments($quotation);
                        
                        $successful = count(array_filter($uploadResults, fn($r) => $r['status'] === 'success'));
                        $failed = count(array_filter($uploadResults, fn($r) => $r['status'] === 'error'));
                        
                        $this->info("   ✅ Upload completed: {$successful} successful, {$failed} failed");
                        
                        if ($failed > 0) {
                            $this->info('   Failed uploads:');
                            foreach ($uploadResults as $result) {
                                if ($result['status'] === 'error') {
                                    $this->line("   ❌ {$result['file']}: {$result['error']}");
                                }
                            }
                        }
                    }
                } elseif ($this->option('dry-run')) {
                    $this->info('   [DRY RUN] Would upload ' . $status['pending'] . ' documents');
                }

            } catch (\Exception $e) {
                $this->error("   ❌ Error processing quotation {$quotation->robaws_id}: " . $e->getMessage());
            }

            $this->newLine();
        }

        // Test 5: Retry failed uploads
        if (!$this->option('dry-run')) {
            $quotationsWithFailures = $quotations->filter(function ($quotation) use ($uploadService) {
                $status = $uploadService->getUploadStatus($quotation);
                return $status['failed'] > 0;
            });

            if ($quotationsWithFailures->count() > 0) {
                if ($this->confirm('Retry failed uploads?')) {
                    $this->info('5. Retrying failed uploads...');
                    
                    foreach ($quotationsWithFailures as $quotation) {
                        try {
                            $retryResults = $uploadService->retryFailedUploads($quotation);
                            
                            $successful = count(array_filter($retryResults, fn($r) => $r['status'] === 'success'));
                            $failed = count(array_filter($retryResults, fn($r) => $r['status'] === 'error'));
                            
                            $this->info("   Quotation {$quotation->robaws_id}: {$successful} successful, {$failed} failed");
                            
                        } catch (\Exception $e) {
                            $this->error("   ❌ Retry failed for {$quotation->robaws_id}: " . $e->getMessage());
                        }
                    }
                }
            }
        }

        $this->newLine();
        $this->info('🎉 Multi-document upload test completed!');
        
        return 0;
    }
}
