<?php

namespace App\Jobs\Intake;

use App\Models\Intake;
use App\Models\IntakeFile;
use App\Jobs\Intake\ExtractPdfDataJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPdfIntakeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes for PDF processing (may need OCR)
    public $tries = 3; // Retry up to 3 times

    public function __construct(
        public Intake $intake,
        public IntakeFile $file
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('ProcessPdfIntakeJob: Starting PDF processing', [
            'intake_id' => $this->intake->id,
            'file_id' => $this->file->id,
            'filename' => $this->file->filename,
            'mime_type' => $this->file->mime_type
        ]);

        try {
            // Validate file exists
            if (!$this->fileExists()) {
                throw new \Exception("PDF file not found in storage: {$this->file->storage_path}");
            }

            // Dispatch extraction job
            ExtractPdfDataJob::dispatch($this->intake, $this->file)
                ->onQueue('pdfs');

            Log::info('ProcessPdfIntakeJob: PDF extraction dispatched', [
                'intake_id' => $this->intake->id,
                'file_id' => $this->file->id
            ]);

        } catch (\Exception $e) {
            Log::error('ProcessPdfIntakeJob: Failed to process PDF', [
                'intake_id' => $this->intake->id,
                'file_id' => $this->file->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update intake status to indicate failure
            $this->intake->update([
                'status' => 'failed',
                'notes' => array_merge($this->intake->notes ?? [], [
                    'pdf_processing_error' => $e->getMessage(),
                    'failed_file' => $this->file->filename
                ])
            ]);

            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    /**
     * Check if the PDF file exists in storage
     */
    private function fileExists(): bool
    {
        try {
            return \Illuminate\Support\Facades\Storage::disk($this->file->storage_disk)
                ->exists($this->file->storage_path);
        } catch (\Exception $e) {
            Log::error('ProcessPdfIntakeJob: Error checking file existence', [
                'file_id' => $this->file->id,
                'storage_disk' => $this->file->storage_disk,
                'storage_path' => $this->file->storage_path,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessPdfIntakeJob failed permanently', [
            'intake_id' => $this->intake->id,
            'file_id' => $this->file->id,
            'filename' => $this->file->filename,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Update intake status to indicate permanent failure
        $this->intake->update([
            'status' => 'failed',
            'notes' => array_merge($this->intake->notes ?? [], [
                'permanent_pdf_error' => $exception->getMessage(),
                'failed_file' => $this->file->filename,
                'attempts' => $this->attempts()
            ])
        ]);
    }
}

