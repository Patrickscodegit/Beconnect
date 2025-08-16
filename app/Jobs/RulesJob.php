<?php

namespace App\Jobs;

use App\Models\Extraction;
use App\Models\Intake;
use App\Services\RuleEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries = 3;
    public $backoff = [30, 60, 120];

    public function __construct(public int $intakeId) 
    {
        $this->onQueue('high');
    }

    public function handle(RuleEngine $engine): void
    {
        $intake = Intake::find($this->intakeId);
        if (!$intake) { 
            Log::warning('RulesJob: intake not found', ['intakeId' => $this->intakeId]); 
            return; 
        }

        Log::info('RulesJob started', ['intake_id' => $intake->id, 'status' => $intake->status]);

        $extraction = Extraction::where('intake_id', $intake->id)->first();
        if (!$extraction) { 
            Log::warning('RulesJob: extraction missing', ['intakeId' => $intake->id]); 
            $intake->update(['status' => 'rules_applied']); 
            return; 
        }

        $raw = $extraction->raw_json;
        if (is_string($raw)) { 
            $raw = json_decode($raw, true) ?? []; 
        }
        if (!is_array($raw)) { 
            Log::warning('RulesJob: extraction not array', ['intakeId' => $intake->id]); 
            $intake->update(['status' => 'rules_applied']); 
            return; 
        }

        try {
            Log::info('RulesJob: applying rules', ['intakeId' => $intake->id]);

            $result = $engine->apply($intake, $raw);
            $intake->update(['status' => 'rules_applied']);

            Log::info('RulesJob: rules applied', [
                'intakeId' => $intake->id,
                'all_verified' => $result['all_verified'] ?? false,
                'notes_count' => count($result['notes'] ?? [])
            ]);

            if (!empty($result['all_verified'])) {
                Log::info('RulesJob: all verified, dispatching Robaws', ['intakeId' => $intake->id]);
                PushRobawsJob::dispatch($intake->id)->onQueue('high');
            } else {
                $notes = $intake->notes ?? [];
                if (!in_array('awaiting_verification', $notes, true)) {
                    $notes[] = 'awaiting_verification';
                    $intake->update(['notes' => $notes]);
                }
                Log::info('RulesJob: awaiting verification', [
                    'intakeId' => $intake->id,
                    'notes' => $result['notes'] ?? []
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('RulesJob failed', [
                'intakeId' => $intake->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $intake->update([
                'status' => 'rules_failed',
                'error_message' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('RulesJob failed after all retries', [
            'intakeId' => $this->intakeId,
            'error' => $exception->getMessage()
        ]);

        $intake = Intake::find($this->intakeId);
        if ($intake) {
            $intake->update([
                'status' => 'rules_failed',
                'error_message' => 'Rules application failed: ' . $exception->getMessage()
            ]);
        }
    }
}
