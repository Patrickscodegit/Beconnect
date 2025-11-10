<?php

namespace Tests\Support\Pipeline\Fakes;

use App\Models\Document;
use App\Services\OcrService;

class FakeOcrService extends OcrService
{
    public function __construct()
    {
        // Skip parent setup that touches binaries
    }

    public function run(Document $document): void
    {
        $document->update([
            'has_text_layer' => true,
            'page_count' => $document->page_count ?? 1,
            'ocr_confidence' => 95,
        ]);
    }

    public function extractFromImage(string $imagePath): string
    {
        return 'Sample OCR text from image.';
    }

    public function extractFromPdf(string $pdfPath): array
    {
        return [
            'text' => 'Sample OCR text from pdf.',
            'page_count' => 1,
        ];
    }
}

