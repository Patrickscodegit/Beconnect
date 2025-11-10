<?php

namespace Tests\Support\Pipeline\Fakes;

use App\Models\Document;
use App\Models\Intake;
use App\Services\PdfService;

class FakePdfService extends PdfService
{
    public function __construct()
    {
        // Bypass parent heavy setup
    }

    public function extractText(string $pdfPath): string
    {
        return 'Sample PDF text extracted for testing.';
    }

    public function detectTextLayer(Document $document): bool
    {
        return true;
    }

    public function getPageCount(string $pdfPath): int
    {
        return 1;
    }

    public function classifyDocuments($documents): void
    {
        foreach ($documents as $document) {
            $document->update(['document_type' => $document->document_type ?? 'invoice']);
        }
    }

    public function collectTextForExtraction(Intake $intake): string
    {
        return 'Combined intake text for pipeline testing.';
    }

    public function collectDocumentsForExtraction(Intake $intake): array
    {
        return [
            [
                'name' => 'test-document.pdf',
                'mime' => 'application/pdf',
                'text' => 'Sample PDF content for extraction.',
            ],
        ];
    }
}

