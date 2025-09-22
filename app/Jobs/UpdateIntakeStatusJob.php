<?php

namespace App\Jobs;

use App\Models\Intake;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateIntakeStatusJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private int $intakeId,
        private string $status,
        private ?string $errorMessage = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Updating intake status', [
            'intake_id' => $this->intakeId,
            'new_status' => $this->status,
            'error_message' => $this->errorMessage
        ]);

        try {
            $intake = Intake::find($this->intakeId);
            if (!$intake) {
                Log::warning('Intake not found for status update', [
                    'intake_id' => $this->intakeId
                ]);
                return;
            }

            $updateData = ['status' => $this->status];
            
            if ($this->errorMessage) {
                $updateData['last_export_error'] = $this->errorMessage;
                $updateData['last_export_error_at'] = now();
            }

            $result = $intake->update($updateData);

            if ($result) {
                Log::info('Intake status updated successfully', [
                    'intake_id' => $this->intakeId,
                    'old_status' => $intake->getOriginal('status'),
                    'new_status' => $this->status
                ]);
            } else {
                Log::error('Failed to update intake status', [
                    'intake_id' => $this->intakeId,
                    'attempted_status' => $this->status
                ]);
            }

        } catch (\Exception $e) {
            Log::error('UpdateIntakeStatusJob failed', [
                'intake_id' => $this->intakeId,
                'status' => $this->status,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }
}
