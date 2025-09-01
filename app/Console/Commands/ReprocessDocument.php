<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Extraction\Strategies\EmailExtractionStrategy;
use App\Services\AiRouter;
use App\Services\Extraction\HybridExtractionPipeline;
use Illuminate\Console\Command;

class ReprocessDocument extends Command
{
    protected $signature = 'document:reprocess {id} {--force : Force reprocessing even if extraction exists}';
    protected $description = 'Reprocess document extraction';

    public function handle()
    {
        $docId = $this->argument('id');
        $document = Document::find($docId);

        if (!$document) {
            $this->error("Document ID {$docId} not found");
            return 1;
        }

        $this->info("Reprocessing document ID: {$document->id}");
        $this->info("Filename: {$document->filename}");
        $this->info("Storage disk: {$document->storage_disk}");

        // Clear existing extraction if force flag is set
        if ($this->option('force') && $document->extraction_data) {
            $document->update([
                'extraction_data' => null,
                'extraction_confidence' => null,
                'extraction_status' => null,
                'extracted_at' => null,
            ]);
            $this->info("Cleared existing extraction data");
        }

        // Check if document is an email
        if ($document->mime_type !== 'message/rfc822' && !str_ends_with(strtolower($document->filename), '.eml')) {
            $this->error("Document is not an email file");
            return 1;
        }

        try {
            // Create the email extraction strategy
            $aiRouter = app(AiRouter::class);
            $hybridPipeline = app(HybridExtractionPipeline::class);
            $strategy = new EmailExtractionStrategy($aiRouter, $hybridPipeline);

            // Run extraction
            $this->info("Running email extraction...");
            $result = $strategy->extract($document);

            if ($result->isSuccessful()) {
                $this->info("✓ Extraction successful!");
                $this->info("  Confidence: {$result->getConfidence()}%");
                
                // Save extraction to database
                $document->update([
                    'extraction_data' => $result->getData(),
                    'extraction_confidence' => $result->getConfidence(),
                    'extraction_status' => 'completed',
                    'extracted_at' => now(),
                ]);
                
                $this->info("✓ Extraction saved to database");
                $this->info("\nExtracted data summary:");
                $data = $result->getData();
                foreach ($data as $key => $value) {
                    if (!empty($value)) {
                        $this->info("  - {$key}: " . (is_array($value) ? count($value) . " items" : "present"));
                    }
                }
            } else {
                $this->error("✗ Extraction failed: {$result->getError()}");
                
                // Save error to database
                $document->update([
                    'extraction_data' => [],
                    'extraction_confidence' => 0,
                    'extraction_status' => 'failed',
                    'extracted_at' => now(),
                ]);
            }

        } catch (\Exception $e) {
            $this->error("Exception during extraction: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
