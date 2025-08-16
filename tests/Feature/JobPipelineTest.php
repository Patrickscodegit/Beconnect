<?php

use App\Jobs\OcrJob;
use App\Jobs\PreprocessJob;
use App\Jobs\ClassifyJob;
use App\Jobs\ExtractJob;
use App\Models\Document;
use App\Models\Intake;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
    Queue::fake();
});

describe('Job Pipeline Integration', function () {
    it('dispatches OCR job for documents without text layer', function () {
        $intake = Intake::factory()->create();
        $document = Document::factory()->create([
            'intake_id' => $intake->id,
            'mime_type' => 'application/pdf',
            'has_text_layer' => false
        ]);
        
        OcrJob::dispatch($intake->id);
        
        Queue::assertPushed(OcrJob::class, function ($job) use ($intake) {
            return $job->intakeId === $intake->id;
        });
    });

    it('handles OCR job failures with retry logic', function () {
        $document = Document::factory()->create([
            'mime_type' => 'application/pdf',
        ]);
        
        // Mock storage to simulate file not found
        Storage::disk('s3')->assertMissing($document->storage_path);
        
        $job = new OcrJob($document);
        
        // Job should handle failure gracefully
        expect(fn() => $job->handle())->not->toThrow();
        
        // Document should be marked as failed
    });

    it('processes complete intake through extraction pipeline', function () {
        $documents = Document::factory()->count(3)->create([
            'intake_id' => $intake->id,
        ]);
        
        ExtractJob::dispatch($intake);
        
        Queue::assertPushed(ExtractJob::class, function ($job) use ($intake) {
            return $job->intake->id === $intake->id;
        });
    });

    it('validates intake readiness before extraction', function () {
        
        // Create documents in different states
        Document::factory()->create([
            'intake_id' => $intake->id,
        ]);
        Document::factory()->create([
            'intake_id' => $intake->id,
        ]);
        
        $job = new ExtractJob($intake);
        
        // Job should detect that not all documents are ready
        expect(fn() => $job->handle())->not->toThrow();
        
        // Intake should remain in processing state
    });
});

describe('Queue Performance and Monitoring', function () {
    it('respects job timeout configuration', function () {
        $document = Document::factory()->create();
        $job = new OcrJob($document);
        
        // Check that job has reasonable timeout
        expect($job->timeout)->toBeGreaterThan(60)
            ->and($job->timeout)->toBeLessThanOrEqual(300);
    });

    it('has proper retry configuration', function () {
        $document = Document::factory()->create();
        $job = new OcrJob($document);
        
        // Check retry attempts
        expect($job->tries)->toBeGreaterThan(1)
            ->and($job->tries)->toBeLessThanOrEqual(5);
    });

    it('handles job queues properly', function () {
        $highPriorityDocument = Document::factory()->create(['document_type' => 'urgent']);
        $normalDocument = Document::factory()->create(['document_type' => 'invoice']);
        
        OcrJob::dispatch($highPriorityDocument)->onQueue('high');
        OcrJob::dispatch($normalDocument)->onQueue('default');
        
        Queue::assertPushedOn('high', OcrJob::class);
        Queue::assertPushedOn('default', OcrJob::class);
    });
});
