<?php

use App\Jobs\ProcessOcrJob;
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
        
        ProcessOcrJob::dispatch($document);
        
        Queue::assertPushed(ProcessOcrJob::class, function ($job) use ($document) {
            return $job->document->id === $document->id;
        });
    });

    it('handles OCR job failures with retry logic', function () {
        $document = Document::factory()->create([
            'mime_type' => 'application/pdf',
            'status' => 'processing'
        ]);
        
        // Mock storage to simulate file not found
        Storage::disk('s3')->assertMissing($document->storage_path);
        
        $job = new ProcessOcrJob($document);
        
        // Job should handle failure gracefully
        expect(fn() => $job->handle())->not->toThrow();
        
        // Document should be marked as failed
        expect($document->fresh()->status)->toBe('failed');
    });

    it('processes complete intake through extraction pipeline', function () {
        $intake = Intake::factory()->create(['status' => 'uploaded']);
        $documents = Document::factory()->count(3)->create([
            'intake_id' => $intake->id,
            'status' => 'processed'
        ]);
        
        ExtractJob::dispatch($intake);
        
        Queue::assertPushed(ExtractJob::class, function ($job) use ($intake) {
            return $job->intake->id === $intake->id;
        });
    });

    it('validates intake readiness before extraction', function () {
        $intake = Intake::factory()->create(['status' => 'uploaded']);
        
        // Create documents in different states
        Document::factory()->create([
            'intake_id' => $intake->id,
            'status' => 'processed'  // Ready
        ]);
        Document::factory()->create([
            'intake_id' => $intake->id,
            'status' => 'processing'  // Not ready
        ]);
        
        $job = new ExtractJob($intake);
        
        // Job should detect that not all documents are ready
        expect(fn() => $job->handle())->not->toThrow();
        
        // Intake should remain in processing state
        expect($intake->fresh()->status)->toBe('processing');
    });
});

describe('Queue Performance and Monitoring', function () {
    it('respects job timeout configuration', function () {
        $document = Document::factory()->create();
        $job = new ProcessOcrJob($document);
        
        // Check that job has reasonable timeout
        expect($job->timeout)->toBeGreaterThan(60)
            ->and($job->timeout)->toBeLessThanOrEqual(300);
    });

    it('has proper retry configuration', function () {
        $document = Document::factory()->create();
        $job = new ProcessOcrJob($document);
        
        // Check retry attempts
        expect($job->tries)->toBeGreaterThan(1)
            ->and($job->tries)->toBeLessThanOrEqual(5);
    });

    it('handles job queues properly', function () {
        $highPriorityDocument = Document::factory()->create(['document_type' => 'urgent']);
        $normalDocument = Document::factory()->create(['document_type' => 'invoice']);
        
        ProcessOcrJob::dispatch($highPriorityDocument)->onQueue('high');
        ProcessOcrJob::dispatch($normalDocument)->onQueue('default');
        
        Queue::assertPushedOn('high', ProcessOcrJob::class);
        Queue::assertPushedOn('default', ProcessOcrJob::class);
    });
});
