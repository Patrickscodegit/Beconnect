<?php

namespace App\Jobs;

use App\Models\Intake;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PushRobawsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $backoff = 30; // seconds

    public function __construct(public int $intakeId) 
    { 
        $this->onQueue('high'); 
    }

    public function handle(): void
    {
        /** @var \App\Services\RobawsService $robaws */
        $robaws = app(\App\Services\RobawsService::class);

        $intake = Intake::findOrFail($this->intakeId);
        $intake->update(['status' => 'pushing_to_robaws']);

        $robawsId = $robaws->createOrUpdateOffer($intake, externalRef: (string)$intake->id);

        $intake->update([
            'status' => 'posted_to_robaws',
            'notes'  => array_merge($intake->notes ?? [], ["robaws_id:{$robawsId}"]),
        ]);
    }

    public function failed(Throwable $e): void
    {
        $intake = Intake::find($this->intakeId);
        if ($intake) {
            $intake->update([
                'status' => 'failed_robaws_push',
                'notes' => array_merge($intake->notes ?? [], ["error: {$e->getMessage()}"])
            ]);
        }
    }
}
