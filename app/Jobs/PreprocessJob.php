<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Intake;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PreprocessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $intakeId) 
    { 
        $this->onQueue('default'); 
    }

    public function handle(): void
    {
        /** @var \App\Services\PdfService $pdf */
        $pdf = app(\App\Services\PdfService::class);

        $intake = Intake::findOrFail($this->intakeId);
        $intake->update(['status' => 'preprocessing']);

        $needsOcr = false;

        /** @var Document $doc */
        foreach ($intake->documents as $doc) {
            $hasText = $pdf->detectTextLayer($doc);
            $doc->update(['has_text_layer' => $hasText]);
            if (!$hasText) $needsOcr = true;
        }

        $intake->update(['status' => 'preprocessed']);

        if ($needsOcr) {
            OcrJob::dispatch($this->intakeId)->onQueue('default');
        } else {
            ClassifyJob::dispatch($this->intakeId)->onQueue('default');
        }
    }
}
