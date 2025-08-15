<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\Intake;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OcrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $intakeId) 
    { 
        $this->onQueue('default'); 
    }

    public function handle(): void
    {
        /** @var \App\Services\OcrService $ocr */
        $ocr = app(\App\Services\OcrService::class);

        $intake = Intake::findOrFail($this->intakeId);
        $intake->update(['status' => 'ocr_processing']);

        /** @var Document $doc */
        foreach ($intake->documents as $doc) {
            if (!$doc->has_text_layer) {
                $ocr->run($doc); // save per-page .txt into MinIO
            }
        }

        $intake->update(['status' => 'ocr_done']);

        ClassifyJob::dispatch($this->intakeId)->onQueue('default');
    }
}
