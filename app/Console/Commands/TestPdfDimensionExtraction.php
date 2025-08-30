<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Extraction\Strategies\PdfExtractionStrategy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TestPdfDimensionExtraction extends Command
{
    protected $signature = 'test:pdf-dimensions {document_id?}';
    protected $description = 'Test PDF dimension extraction functionality';

    public function handle()
    {
        $documentId = $this->argument('document_id');
        
        if ($documentId) {
            $document = Document::find($documentId);
            if (!$document) {
                $this->error("Document with ID {$documentId} not found");
                return 1;
            }
        } else {
            // Find a PDF document to test with
            $document = Document::where('file_type', 'application/pdf')
                ->orWhere('filename', 'like', '%.pdf')
                ->latest()
                ->first();
                
            if (!$document) {
                $this->error('No PDF documents found in the database');
                return 1;
            }
        }
        
        $this->info("Testing PDF dimension extraction with: {$document->filename}");
        $this->info("Document ID: {$document->id}");
        $this->info("File type: {$document->file_type}");
        $this->newLine();
        
        // Test the PDF extraction strategy
        $strategy = app(PdfExtractionStrategy::class);
        
        $this->info('🔍 Starting PDF extraction...');
        $result = $strategy->extract($document);
        
        if (!$result->isSuccessful()) {
            $this->error('PDF extraction failed: ' . $result->getErrorMessage());
            return 1;
        }
        
        $data = $result->getData();
        
        // Display extraction results with focus on dimensions
        $this->displayExtractionResults($data);
        
        // Test pattern extraction directly on PDF text
        $this->testPatternExtractionDirectly($document);
        
        return 0;
    }
    
    private function displayExtractionResults(array $data)
    {
        $this->info('📊 Extraction Results:');
        $this->newLine();
        
        // Vehicle information
        if (!empty($data['vehicle'])) {
            $this->comment('🚗 Vehicle Information:');
            $vehicle = $data['vehicle'];
            
            $vehicleTable = [];
            foreach ($vehicle as $key => $value) {
                if (is_array($value)) {
                    $vehicleTable[] = [$key, json_encode($value)];
                } else {
                    $vehicleTable[] = [$key, $value];
                }
            }
            
            $this->table(['Field', 'Value'], $vehicleTable);
            
            // Highlight dimensions specifically
            if (isset($vehicle['dimensions'])) {
                $this->info('✅ Dimensions found!');
                $dims = $vehicle['dimensions'];
                $this->line("  Length: " . ($dims['length_m'] ?? 'N/A') . " m");
                $this->line("  Width: " . ($dims['width_m'] ?? 'N/A') . " m");
                $this->line("  Height: " . ($dims['height_m'] ?? 'N/A') . " m");
                if (isset($dims['source'])) {
                    $this->line("  Source: " . $dims['source']);
                }
            } else {
                $this->error('❌ No dimensions found in extraction');
            }
            
            $this->newLine();
        } else {
            $this->warn('No vehicle information extracted');
        }
        
        // Other sections summary
        $sections = ['contact', 'shipment', 'pricing', 'dates'];
        foreach ($sections as $section) {
            if (!empty($data[$section])) {
                $count = count(array_filter($data[$section]));
                $this->line("✅ {$section}: {$count} fields extracted");
            } else {
                $this->line("❌ {$section}: No data extracted");
            }
        }
        
        $this->newLine();
    }
    
    private function testPatternExtractionDirectly(Document $document)
    {
        $this->info('🔍 Testing pattern extraction directly on PDF text...');
        
        try {
            // Read PDF and extract text
            $pdfContent = Storage::get($document->file_path);
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseContent($pdfContent);
            $text = $pdf->getText();
            
            $this->comment('📄 PDF Text Sample (first 500 chars):');
            $this->line(substr(trim($text), 0, 500) . '...');
            $this->newLine();
            
            // Test dimension patterns directly
            $this->testDimensionPatterns($text);
            
        } catch (\Exception $e) {
            $this->error('Failed to extract PDF text: ' . $e->getMessage());
        }
    }
    
    private function testDimensionPatterns(string $text)
    {
        $this->comment('🎯 Testing dimension patterns on extracted text:');
        
        $patterns = [
            'dimensions_labeled' => '/(?:dimensions?|size|measurements?)[\s:=]+(\d+\.?\d*)\s*[×x×X*]\s*(\d+\.?\d*)\s*[×x×X*]\s*(\d+\.?\d*)\s*(m|cm|mm|ft|feet|in|inches)?/i',
            'dimensions_lwh' => '/(?:L\s*[×x×X*]\s*W\s*[×x×X*]\s*H|LWH)[\s:=]*(\d+\.?\d*)\s*[×x×X*]\s*(\d+\.?\d*)\s*[×x×X*]\s*(\d+\.?\d*)\s*(m|cm|mm|ft|feet|in|inches)?/i',
            'dimensions_parentheses' => '/\((\d+\.?\d*)\s*[×x×X*]\s*(\d+\.?\d*)\s*[×x×X*]\s*(\d+\.?\d*)\s*(m|cm|mm|ft|feet|in|inches)?\)/',
            'dimensions_metric' => '/(\d+\.?\d*)\s*[×x×X*]\s*(\d+\.?\d*)\s*[×x×X*]\s*(\d+\.?\d*)\s*(m|M|cm|CM|mm|MM|meters?|centimeters?|millimeters?)/',
            'length_labeled' => '/(?:^|\b)(?:vehicle\s+)?length[\s:=]+(\d+\.?\d*)\s*(m|cm|mm|ft|feet|in|inches)\b/i',
            'width_labeled' => '/(?:^|\b)(?:vehicle\s+)?width[\s:=]+(\d+\.?\d*)\s*(m|cm|mm|ft|feet|in|inches)\b/i',
            'height_labeled' => '/(?:^|\b)(?:vehicle\s+)?height[\s:=]+(\d+\.?\d*)\s*(m|cm|mm|ft|feet|in|inches)\b/i',
            'length_single_letter' => '/\bL[\s:=]+(\d+\.?\d*)\s*(m|cm|mm|ft|feet|in|inches)\b/i',
            'width_single_letter' => '/\bW[\s:=]+(\d+\.?\d*)\s*(m|cm|mm|ft|feet|in|inches)\b/i',
            'height_single_letter' => '/\bH[\s:=]+(\d+\.?\d*)\s*(m|cm|mm|ft|feet|in|inches)\b/i',
        ];
        
        $foundMatches = false;
        
        foreach ($patterns as $patternName => $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
                $foundMatches = true;
                $this->line("✅ Pattern '{$patternName}' found " . count($matches) . " match(es):");
                
                foreach ($matches as $i => $match) {
                    $this->line("  Match " . ($i + 1) . ": " . $match[0]);
                    if (count($match) >= 4) {
                        $this->line("    L: {$match[1]}, W: {$match[2]}, H: {$match[3]}");
                        if (isset($match[4])) {
                            $this->line("    Unit: {$match[4]}");
                        }
                    }
                }
                $this->newLine();
            }
        }
        
        if (!$foundMatches) {
            $this->warn('❌ No dimension patterns found in PDF text');
            
            // Look for any numbers that might be dimensions
            $this->comment('🔍 Looking for potential dimension-related text:');
            $dimensionKeywords = ['length', 'width', 'height', 'size', 'dimension', 'measure', 'L:', 'W:', 'H:', 'LxW', 'LWH'];
            
            foreach ($dimensionKeywords as $keyword) {
                if (stripos($text, $keyword) !== false) {
                    $this->line("Found keyword: '{$keyword}'");
                    
                    // Show context around the keyword
                    $position = stripos($text, $keyword);
                    $context = substr($text, max(0, $position - 50), 100);
                    $this->line("Context: " . trim($context));
                    $this->newLine();
                }
            }
        }
    }
}
