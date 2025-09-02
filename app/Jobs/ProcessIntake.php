<?php

namespace App\Jobs;

use App\Models\Intake;
use App\Jobs\ExtractDocumentData;
use App\Services\ExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessIntake implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $intake;

    /**
     * Create a new job instance.
     */
    public function __construct(Intake $intake)
    {
        $this->intake = $intake;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Processing intake', ['intake_id' => $this->intake->id]);

        try {
            // Get all files for this intake (NEW FILE-BASED APPROACH)
            $files = $this->intake->files;

            if ($files->isEmpty()) {
                Log::warning('No files found for intake', ['intake_id' => $this->intake->id]);
                $this->intake->update(['status' => 'failed']);
                return;
            }

            // Extract data from all files
            $extractedData = [];
            $hasContactInfo = $this->hasContactInfo();

            foreach ($files as $file) {
                Log::info('Processing file for extraction', [
                    'intake_id' => $this->intake->id,
                    'file_id' => $file->id,
                    'filename' => $file->filename,
                    'mime_type' => $file->mime_type
                ]);

                // Use ExtractionService to process the file
                $fileData = app(ExtractionService::class)->extractFromFile($file);
                if ($fileData) {
                    $extractedData[] = $fileData;
                }
            }

            // Merge extracted data with seeded contact information
            $mergedData = $this->mergeContactData($extractedData);

            // Update intake with merged extraction data
            $this->intake->update([
                'extraction_data' => $mergedData,
                'status' => 'processed'
            ]);

            // Check if we have sufficient contact info for export
            if ($this->shouldExport($mergedData)) {
                Log::info('Contact info sufficient - ready for export', [
                    'intake_id' => $this->intake->id
                ]);
                // Could dispatch export job here if auto-export is desired
            } else {
                Log::info('Contact info insufficient - gating export', [
                    'intake_id' => $this->intake->id,
                    'has_contact_email' => !empty($mergedData['contact_email']),
                    'has_contact_phone' => !empty($mergedData['contact_phone'])
                ]);
            }

            Log::info('Intake processing completed', [
                'intake_id' => $this->intake->id,
                'files_processed' => $files->count(),
                'ready_for_export' => $this->shouldExport($mergedData)
            ]);

        } catch (\Exception $e) {
            Log::error('Error processing intake', [
                'intake_id' => $this->intake->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->intake->update(['status' => 'failed']);
            throw $e;
        }
    }

    /**
     * Check if intake already has contact info from seeding
     */
    private function hasContactInfo(): bool
    {
        return !empty($this->intake->contact_email) || !empty($this->intake->contact_phone);
    }

    /**
     * Merge extracted data with seeded contact information
     */
    private function mergeContactData(array $extractedData): array
    {
        $merged = [
            'files' => $extractedData,
            'contact_email' => $this->intake->contact_email,
            'contact_phone' => $this->intake->contact_phone,
            'customer_name' => $this->intake->customer_name,
        ];

        // If no seeded contact info, try to extract from files
        if (empty($merged['contact_email']) && empty($merged['contact_phone'])) {
            foreach ($extractedData as $fileData) {
                if (!empty($fileData['contact_email']) && empty($merged['contact_email'])) {
                    $merged['contact_email'] = $fileData['contact_email'];
                }
                if (!empty($fileData['contact_phone']) && empty($merged['contact_phone'])) {
                    $merged['contact_phone'] = $fileData['contact_phone'];
                }
                if (!empty($fileData['customer_name']) && empty($merged['customer_name'])) {
                    $merged['customer_name'] = $fileData['customer_name'];
                }
            }

            // Update intake with extracted contact info
            $this->intake->update([
                'contact_email' => $merged['contact_email'],
                'contact_phone' => $merged['contact_phone'],
                'customer_name' => $merged['customer_name'],
            ]);
        }

        return $merged;
    }

    /**
     * Determine if intake is ready for export (has contact info)
     */
    private function shouldExport(array $data): bool
    {
        return !empty($data['contact_email']) || !empty($data['contact_phone']);
    }
}
