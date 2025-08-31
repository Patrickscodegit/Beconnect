<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Robaws\RobawsExportService;

class UploadDocumentToOffer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'offers:upload-doc {offer_id} {file_path} {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Upload a document to a Robaws offer (for testing/ops)';

    /**
     * Execute the console command.
     */
    public function handle(RobawsExportService $service): int
    {
        $offerId = $this->argument('offer_id');
        $filePath = $this->argument('file_path');
        $jsonOutput = $this->option('json');

        try {
            $result = $service->uploadDocumentToOffer($offerId, $filePath);

            if ($jsonOutput) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT));
            } else {
                $this->info("Upload Result:");
                $this->table(
                    ['Field', 'Value'],
                    [
                        ['Status', $result['status']],
                        ['Document ID', $result['document']['id'] ?? 'null'],
                        ['Filename', $result['document']['name']],
                        ['MIME Type', $result['document']['mime'] ?? 'null'],
                        ['Size', $result['document']['size'] ?? 'null'],
                        ['SHA256', $result['document']['sha256'] ?? 'null'],
                        ['Error', $result['error'] ?? 'none'],
                        ['Reason', $result['reason'] ?? 'none'],
                    ]
                );
            }

            return $result['status'] === 'error' ? 1 : 0;

        } catch (\Exception $e) {
            $this->error("Command failed: " . $e->getMessage());
            return 1;
        }
    }
}
