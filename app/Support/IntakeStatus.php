<?php

namespace App\Support;

use App\Models\Intake;

trait IntakeStatus
{
    protected function setStatus(int $intakeId, string $status): void
    {
        Intake::whereKey($intakeId)->update(['status' => $status]);
    }
    
    protected function addNote(int $intakeId, string $note): void
    {
        $intake = Intake::find($intakeId);
        if ($intake) {
            $notes = $intake->notes ?? [];
            $notes[] = $note;
            $intake->update(['notes' => $notes]);
        }
    }
}
