<?php

namespace App\Console\Commands;

use App\Models\Intake;
use App\Models\Document;
use App\Models\Extraction;
use Illuminate\Console\Command;

class TestModalButton extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:modal-button';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the modal button display and functionality';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Testing Modal Button Display');
        $this->line('===============================');

        // Find the most recent intake with extraction data
        $intake = Intake::whereHas('extraction', function($query) {
            $query->where('status', 'completed');
        })->latest()->first();

        if (!$intake) {
            $this->error('No intakes with completed extractions found');
            return;
        }

        $extraction = $intake->extraction;
        
        $this->info('Found test extraction:');
        $this->line("  Intake ID: {$intake->id}");
        $this->line("  Extraction ID: {$extraction->id}");
        $this->line("  Status: {$extraction->status}");
        $this->line("  Confidence: " . number_format($extraction->confidence * 100, 1) . '%');
        
        // Check the extracted data structure
        $data = $extraction->extracted_data;
        
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        
        $this->line('');
        $this->info('Data structure available for modal:');
        
        if ($data && is_array($data)) {
            $this->line('  ✅ Contact info: ' . (isset($data['contact']) ? 'YES' : 'NO'));
            $this->line('  ✅ Shipping info: ' . (isset($data['shipment']) ? 'YES' : 'NO'));
            $this->line('  ✅ Vehicle info: ' . (isset($data['vehicle']) ? 'YES' : 'NO'));
            $this->line('  ✅ Messages: ' . (isset($data['messages']) ? 'YES' : 'NO'));
            
            // Show contact details if available
            if (isset($data['contact'])) {
                $contact = $data['contact'];
                $this->line('');
                $this->line('Contact Details:');
                $this->line('  Name: ' . ($contact['name'] ?? 'N/A'));
                $this->line('  Phone: ' . ($contact['phone'] ?? 'N/A'));
                $this->line('  Email: ' . ($contact['email'] ?? 'N/A'));
            }
            
            $this->line('');
            $this->info('✅ Modal should display properly with Copy All Data button');
            $this->line('📍 Navigate to: http://127.0.0.1:8000/admin/intakes');
            $this->line("📍 Click the 'View Extraction' button for Intake #{$intake->id}");
            $this->line('📍 The "Copy All Data" button should be visible in the top-right of the Extracted Information section');
            
        } else {
            $this->warn('⚠️  No structured data found - modal may be empty');
        }
        
        $this->line('');
        $this->info('Modal template location: resources/views/filament/modals/extraction-results.blade.php');
        $this->info('Button styling: Blue outline with hover effects');
    }
}
