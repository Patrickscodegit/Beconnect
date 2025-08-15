<?php

namespace App\Jobs;

use App\Models\Extraction;
use App\Models\Intake;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $intakeId) 
    { 
        $this->onQueue('high'); 
    }

    public function handle(): void
    {
        /** @var \App\Services\RuleEngine $rules */
        $rules = app(\App\Services\RuleEngine::class);

        $intake = Intake::findOrFail($this->intakeId);
        $intake->update(['status' => 'applying_rules']);
        
        $extraction = Extraction::where('intake_id', $intake->id)->firstOrFail();

        $summary = $rules->apply($intake, $extraction->raw_json); // persists normalized vehicles/parties, returns ['all_verified'=>bool]

        $intake->update(['status' => 'rules_applied']);

        if (!empty($summary['all_verified'])) {
            PushRobawsJob::dispatch($this->intakeId)->onQueue('high');
        }
        // else keep intake awaiting verification by admin
    }
}
