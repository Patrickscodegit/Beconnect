<?php

namespace App\Jobs;

use App\Models\Intake;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ClassifyJob implements ShouldQueue
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
        $intake->update(['status' => 'classifying']);

        // Minimal heuristic/classifier stub
        $pdf->classifyDocuments($intake->documents);

        $intake->update(['status' => 'classified']);

        ExtractJob::dispatch($this->intakeId)->onQueue('high');
    }
}
