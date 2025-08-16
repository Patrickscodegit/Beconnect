<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Intake;
use App\Services\OcrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class OcrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 3;
    public $maxExceptions = 1;
    public $backoff = [10, 30, 60]; // seconds

    public function __construct(public int $intakeId) 
    { 
        $this->onQueue('default'); 
    }

    public function handle(OcrService $ocr): void
    {
        $intake = Intake::with('documents')->find($this->intakeId);
        if (!$intake) { 
            Log::warning('OcrJob: intake not found', ['intakeId' => $this->intakeId]); 
            return; 
        }

        Log::info('OcrJob: starting', ['intake_id' => $intake->id]);

        foreach ($intake->documents as $doc) {
            if (!$doc->has_text_layer && str_starts_with($doc->mime_type, 'application/pdf')) {
                try {
                    Log::info('OcrJob: processing document', [
                        'intake_id' => $intake->id,
                        'document_id' => $doc->id,
                        'filename' => $doc->filename
                    ]);
                    
                    // Use existing OcrService - it's already idempotent
                    $ocr->run($doc);
                    
                } catch (\Throwable $e) {
                    Log::error('OcrJob: document processing failed', [
                        'intake_id' => $intake->id,
                        'document_id' => $doc->id,
                        'error' => $e->getMessage()
                    ]);
                    // Continue with other documents rather than failing the entire job
                }
            }
        }

        $intake->update(['status' => 'ocr_done']);
        
        Log::info('OcrJob: dispatching classify', ['intake_id' => $intake->id]);
        dispatch(new ClassifyJob($intake->id))->onQueue('default');
    }

    public function failed(Exception $exception): void
    {
        Log::error('OCR job failed after all retries', [
            'intake_id' => $this->intakeId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        $intake = Intake::find($this->intakeId);
        if ($intake) {
            $intake->update([
                'status' => 'ocr_failed',
                'error_message' => 'OCR processing failed after ' . $this->attempts() . ' attempts: ' . $exception->getMessage()
            ]);
        }
    }
}
