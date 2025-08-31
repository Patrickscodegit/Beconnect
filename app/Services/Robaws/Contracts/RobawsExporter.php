<?php

namespace App\Services\Robaws\Contracts;

use App\Models\Intake;
use App\Models\Document;

interface RobawsExporter
{
    /**
     * Export all documents in an intake to a single Robaws offer
     */
    public function exportIntake(Intake $intake, array $options = []): array;

    /**
     * Export a single document to Robaws
     */
    public function exportDocument(Document $document): array;

    /**
     * Upload a document to an existing Robaws offer with idempotency
     */
    public function uploadDocumentToRobaws(Document $document, string $robawsOfferId): array;
}
