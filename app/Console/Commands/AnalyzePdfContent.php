<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Smalot\PdfParser\Parser;
use Illuminate\Support\Facades\Storage;

class AnalyzePdfContent extends Command
{
    protected $signature = 'analyze:pdf {document_id?}';
    protected $description = 'Analyze PDF content for dimension-related information';

    public function handle()
    {
        $documentId = $this->argument('document_id') ?? 8;
        $document = Document::find($documentId);
        
        if (!$document) {
            $this->error("Document with ID {$documentId} not found");
            return 1;
        }
        
        $this->info("Analyzing PDF: {$document->filename}");
        
        try {
            $pdfContent = Storage::get($document->file_path);
            $parser = new Parser();
            $pdf = $parser->parseContent($pdfContent);
            $text = $pdf->getText();
            
            $this->info("PDF Text Content:");
            $this->line(str_repeat("=", 80));
            $this->line($text);
            $this->line(str_repeat("=", 80));
            
            // Look specifically for dimension-related keywords
            $this->info("Searching for dimension-related keywords:");
            $keywords = [
                'dimension', 'dimensions', 'size', 'length', 'width', 'height',
                'L:', 'W:', 'H:', 'LxW', 'LWH', 'measurements', 'measure'
            ];
            
            foreach ($keywords as $keyword) {
                if (stripos($text, $keyword) !== false) {
                    $this->line("✅ Found: '{$keyword}'");
                    
                    // Show context
                    $pos = stripos($text, $keyword);
                    $context = substr($text, max(0, $pos - 50), 150);
                    $this->line("   Context: " . trim($context));
                } else {
                    $this->line("❌ Not found: '{$keyword}'");
                }
            }
            
            // Look for number patterns that might be dimensions
            $this->info("Looking for number patterns:");
            if (preg_match_all('/\d+[\.\d]*\s*[xX×]\s*\d+[\.\d]*\s*[xX×]\s*\d+[\.\d]*/', $text, $matches)) {
                $this->line("Found LxWxH patterns:");
                foreach ($matches[0] as $match) {
                    $this->line("  - " . trim($match));
                }
            } else {
                $this->line("No LxWxH patterns found");
            }
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("Error analyzing PDF: " . $e->getMessage());
            return 1;
        }
    }
}
