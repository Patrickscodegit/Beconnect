<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Intake;
use App\Services\PdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PreprocessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries = 3;

    public function __construct(public int $intakeId) 
    { 
        $this->onQueue('default'); 
    }

    public function handle(PdfService $pdf): void
    {
        $intake = Intake::with('documents')->find($this->intakeId);
        if (!$intake) { 
            Log::warning('PreprocessJob: intake not found', ['intakeId' => $this->intakeId]); 
            return; 
        }

        Log::info('PreprocessJob: starting', ['intake_id' => $intake->id]);

        $needsOcr = false;

        /** @var Document $doc */
        foreach ($intake->documents as $doc) {
            $hasText = $pdf->detectTextLayer($doc);
            
            if ($doc->has_text_layer !== $hasText) {
                $doc->has_text_layer = $hasText;
                $doc->save();
                
                Log::info('PreprocessJob: updated text layer flag', [
                    'document_id' => $doc->id,
                    'has_text_layer' => $hasText
                ]);
            }
            
            if (!$hasText && str_starts_with($doc->mime_type, 'application/pdf')) {
                $needsOcr = true;
            }
        }

        $intake->update(['status' => 'preprocessed']);

        if ($needsOcr) {
            Log::info('PreprocessJob: dispatching OCR', ['intake_id' => $intake->id]);
            dispatch(new OcrJob($intake->id))->onQueue('default');
        } else {
            Log::info('PreprocessJob: skipping OCR, dispatching classify', ['intake_id' => $intake->id]);
            dispatch(new ClassifyJob($intake->id))->onQueue('default');
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('PreprocessJob failed', [
            'intakeId' => $this->intakeId,
            'error' => $exception->getMessage()
        ]);

        $intake = Intake::find($this->intakeId);
        if ($intake) {
            $intake->update([
                'status' => 'preprocess_failed',
                'error_message' => $exception->getMessage()
            ]);
        }
    }
}
