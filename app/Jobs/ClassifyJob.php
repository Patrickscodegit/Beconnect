<?php

namespace App\Jobs;

use App\Models\Intake;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ClassifyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries = 3;

    public function __construct(public int $intakeId) 
    { 
        $this->onQueue('default'); 
    }

    public function handle(): void
    {
        $intake = Intake::find($this->intakeId);
        if (!$intake) {
            Log::warning('ClassifyJob: intake not found', ['intakeId' => $this->intakeId]);
            return;
        }

        Log::info('ClassifyJob: processing', ['intake_id' => $intake->id]);

        // Optional: Use existing classification if available
        /** @var \App\Services\PdfService $pdf */
        $pdf = app(\App\Services\PdfService::class);
        
        try {
            // Keep existing classification logic for now
            $pdf->classifyDocuments($intake->documents);
        } catch (\Throwable $e) {
            Log::warning('ClassifyJob: classification failed, continuing', [
                'intake_id' => $intake->id,
                'error' => $e->getMessage()
            ]);
        }

        $intake->update(['status' => 'classified']);

        // Continue to extraction
        Log::info('ClassifyJob: dispatching extract', ['intake_id' => $intake->id]);
        dispatch(new ExtractJob($intake->id))->onQueue('high');
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ClassifyJob failed', [
            'intakeId' => $this->intakeId,
            'error' => $exception->getMessage()
        ]);

        $intake = Intake::find($this->intakeId);
        if ($intake) {
            $intake->update([
                'status' => 'classify_failed',
                'error_message' => $exception->getMessage()
            ]);
        }
    }
}
