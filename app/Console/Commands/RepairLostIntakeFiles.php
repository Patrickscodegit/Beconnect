<?php

namespace App\Console\Commands;

use App\Models\Intake;
use App\Models\IntakeFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RepairLostIntakeFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'intake:repair-lost-files {--dry-run : Show what would be repaired without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Repair orphaned intakes that lack IntakeFile records';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        
        $this->info('Finding orphaned intakes without IntakeFile records...');
        
        // Find intakes without associated IntakeFile records
        $orphanedIntakes = Intake::whereDoesntHave('files')->get();
        
        if ($orphanedIntakes->isEmpty()) {
            $this->info('No orphaned intakes found.');
            return 0;
        }
        
        $this->info("Found {$orphanedIntakes->count()} orphaned intakes:");
        
        foreach ($orphanedIntakes as $intake) {
            $this->line("- Intake #{$intake->id} (Status: {$intake->status}, Source: {$intake->source})");
            
            if (!$dryRun) {
                $this->repairIntake($intake);
            }
        }
        
        if ($dryRun) {
            $this->warn('This was a dry run. Use --no-dry-run to actually repair the intakes.');
        } else {
            $this->info('Repair completed!');
        }
        
        return 0;
    }
    
    private function repairIntake(Intake $intake): void
    {
        // For screenshot/text intakes, we'll create a placeholder file if possible
        if (in_array($intake->source, ['screenshot', 'text_input', 'screenshot_api', 'text_api'])) {
            $this->createPlaceholderFile($intake);
        } else {
            // For other sources, set extraction data if possible
            $this->setExtractedStatus($intake);
        }
    }
    
    private function createPlaceholderFile(Intake $intake): void
    {
        $filename = match($intake->source) {
            'screenshot', 'screenshot_api' => 'lost_screenshot_' . $intake->id . '.placeholder',
            'text_input', 'text_api' => 'lost_text_' . $intake->id . '.placeholder',
            default => 'lost_file_' . $intake->id . '.placeholder'
        };
        
        $content = "This is a placeholder for a lost file from intake #{$intake->id} (Source: {$intake->source})";
        $storagePath = 'intakes/' . $intake->id . '/' . $filename;
        
        Storage::disk('local')->put($storagePath, $content);
        
        IntakeFile::create([
            'intake_id' => $intake->id,
            'filename' => $filename,
            'storage_path' => $storagePath,
            'storage_disk' => 'local',
            'mime_type' => 'text/plain',
            'file_size' => strlen($content),
        ]);
        
        $this->line("  ✓ Created placeholder file: {$filename}");
    }
    
    private function setExtractedStatus(Intake $intake): void
    {
        if (!$intake->extraction_data) {
            $intake->extraction_data = json_encode([
                'note' => 'Extracted data unavailable - intake repaired without original file',
                'repair_date' => now()->toISOString(),
                'original_source' => $intake->source
            ]);
        }
        
        if ($intake->status === 'pending') {
            $intake->status = 'extracted';
        }
        
        $intake->save();
        
        $this->line("  ✓ Set extracted status with placeholder data");
    }
}
