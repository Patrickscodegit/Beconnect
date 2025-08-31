<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use App\Services\EmailDocumentService;
use App\Models\Intake;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Make sure tests never touch DO Spaces
    config([
        'filesystems.default' => 'local',
        'filesystems.disks.local.root' => storage_path('framework/testing/local'),
        // If your app uses a "documents" disk, fake it too:
        'filesystems.disks.documents' => [
            'driver' => 'local',
            'root'   => storage_path('framework/testing/documents'),
        ],
    ]);
    Storage::fake('local');
    Storage::fake('documents');
});

it('deduplicates per intake using the ingestion path', function () {
    /** @var EmailDocumentService $svc */
    $svc = app(EmailDocumentService::class);

    $intakeA = Intake::factory()->create();
    $intakeB = Intake::factory()->create();

    $raw = <<<EML
From: Badr <badr.algothami@gmail.com>
To: quotes@example.com
Message-ID: <abc123@example.com>
Subject: Transport BMW Série 7 de Bruxelles vers Djeddah
Content-Type: text/plain; charset=utf-8

Bonjour, je souhaite transporter une BMW Série 7 de Bruxelles vers Djeddah.
EML;

    // Store the email content in fake storage
    Storage::disk('documents')->put('inbox/bmw.eml', $raw);

    // 1) Ingest into Intake A using the real service (sets message_id + sha consistently)
    $resultA = $svc->ingestStoredEmail(
        disk: 'documents',
        path: 'inbox/bmw.eml',
        intakeId: $intakeA->id,
        originalFilename: 'bmw.eml'
    );

    expect($resultA)
        ->toBeArray()
        ->and($resultA['document']->intake_id)->toBe($intakeA->id);

    // 2) Same email, same intake => duplicate TRUE
    $dupA = $svc->isDuplicate($raw, $intakeA->id);
    expect($dupA['is_duplicate'])->toBeTrue()
        ->and($dupA['document_id'])->toBe($resultA['document']->id)
        ->and($dupA['document']->id)->toBe($resultA['document']->id)
        ->and($dupA['matched_on'])->toBeIn(['message_id', 'content_sha']);

    // 3) Same email, different intake => duplicate FALSE
    $dupB = $svc->isDuplicate($raw, $intakeB->id);
    expect($dupB['is_duplicate'])->toBeFalse()
        ->and($dupB['document_id'])->toBeNull()
        ->and($dupB['document'])->toBeNull()
        ->and($dupB['matched_on'])->toBeNull();
});

it('detects duplicates by content sha when message id differs', function () {
    /** @var EmailDocumentService $svc */
    $svc = app(EmailDocumentService::class);

    $intake = Intake::factory()->create();

    $raw1 = <<<EML
From: sender@example.com
To: quotes@example.com
Message-ID: <first@example.com>
Subject: Test Email

This is the same content.
EML;

    $raw2 = <<<EML
From: sender@example.com
To: quotes@example.com
Message-ID: <second@example.com>
Subject: Test Email

This is the same content.
EML;

    // Store both emails in fake storage
    Storage::disk('documents')->put('inbox/email1.eml', $raw1);
    Storage::disk('documents')->put('inbox/email2.eml', $raw2);

    // Ingest first email
    $result1 = $svc->ingestStoredEmail(
        disk: 'documents',
        path: 'inbox/email1.eml',
        intakeId: $intake->id,
        originalFilename: 'email1.eml'
    );

    // Second email should be duplicate by content_sha
    $dup = $svc->isDuplicate($raw2, $intake->id);
    expect($dup['is_duplicate'])->toBeTrue()
        ->and($dup['matched_on'])->toBe('content_sha')
        ->and($dup['document']->id)->toBe($result1['document']->id);
});

it('prevents duplicates within same intake but allows across different intakes', function () {
    /** @var EmailDocumentService $svc */
    $svc = app(EmailDocumentService::class);

    $intakeA = Intake::factory()->create();
    $intakeB = Intake::factory()->create();

    $raw = <<<EML
From: test@example.com
To: quotes@example.com
Message-ID: <shared@example.com>
Subject: Shared Email

This email will be uploaded to both intakes.
EML;

    Storage::disk('documents')->put('inbox/shared.eml', $raw);

    // Ingest into Intake A
    $resultA = $svc->ingestStoredEmail(
        disk: 'documents',
        path: 'inbox/shared.eml',
        intakeId: $intakeA->id,
        originalFilename: 'shared.eml'
    );

    // Same email in same intake = duplicate
    $dupSame = $svc->isDuplicate($raw, $intakeA->id);
    expect($dupSame['is_duplicate'])->toBeTrue()
        ->and($dupSame['document']->id)->toBe($resultA['document']->id);

    // Same email in different intake = NOT duplicate (intake-scoped)
    $dupDiff = $svc->isDuplicate($raw, $intakeB->id);
    expect($dupDiff['is_duplicate'])->toBeFalse();

    // Can ingest into second intake
    $resultB = $svc->ingestStoredEmail(
        disk: 'documents',
        path: 'inbox/shared.eml',
        intakeId: $intakeB->id,
        originalFilename: 'shared.eml'
    );

    // Should create separate documents
    expect($resultA['document']->id)->not->toBe($resultB['document']->id)
        ->and($resultA['document']->intake_id)->toBe($intakeA->id)
        ->and($resultB['document']->intake_id)->toBe($intakeB->id);
});
