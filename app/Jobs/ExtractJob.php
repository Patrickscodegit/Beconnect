<?php

namespace App\Jobs;

use App\Models\Extraction;
use App\Models\Intake;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExtractJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $intakeId) 
    { 
        $this->onQueue('high'); 
    }

    public function handle(): void
    {
        /** @var \App\Services\LlmExtractor $llm */
        $llm = app(\App\Services\LlmExtractor::class);
        /** @var \App\Services\PdfService $pdf */
        $pdf = app(\App\Services\PdfService::class);

        $intake = Intake::findOrFail($this->intakeId);
        $intake->update(['status' => 'extracting']);

        // Collect cleaned text (respect page limits/chunking inside PdfService)
        $payload = $pdf->collectTextForExtraction($intake);

        // Strict JSON extraction
        $result = $llm->extract($payload); // returns ['json' => array, 'confidence' => 0.xx]

        Extraction::updateOrCreate(
            ['intake_id' => $intake->id],
            ['raw_json' => $result['json'], 'confidence' => $result['confidence']]
        );

        $intake->update(['status' => 'llm_extracted']);

        RulesJob::dispatch($this->intakeId)->onQueue('high');
    }
}
