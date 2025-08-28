<?php

namespace App\Services\Display;

use Illuminate\Support\Str;

class ExtractionDataFormatter
{
    /**
     * Format extraction data for clear display in Filament
     */
    public function formatForDisplay(array $extractedData): string
    {
        $output = [];
        
        // Add clear headers and separators
        $output[] = $this->formatSection('📄 DATA EXTRACTED FROM DOCUMENT', 'info');
        $output[] = $this->formatNote('The following information was found in the actual document:');
        $output[] = '';
        
        // Format document data
        if (!empty($extractedData['document_data'])) {
            $output[] = $this->formatDocumentData($extractedData['document_data']);
        } else {
            $output[] = $this->formatWarning('No data extracted from document');
        }
        
        $output[] = "\n" . str_repeat('─', 60) . "\n";
        
        // AI Enhanced Data Section
        $output[] = $this->formatSection('🤖 AI-ENHANCED / DATABASE-ADDED DATA', 'warning');
        $output[] = $this->formatNote('The following information was NOT in the document but was added by AI/Database:');
        $output[] = '';
        
        if (!empty($extractedData['ai_enhanced_data'])) {
            $output[] = $this->formatAiEnhancedData($extractedData['ai_enhanced_data']);
        } else {
            $output[] = $this->formatSuccess('No AI enhancement needed - all data from document');
        }
        
        $output[] = "\n" . str_repeat('─', 60) . "\n";
        
        // Data Attribution Summary
        $output[] = $this->formatSection('📊 DATA SOURCE SUMMARY', 'default');
        $output[] = $this->formatAttributionSummary($extractedData['data_attribution'] ?? []);
        
        // Metadata section
        if (!empty($extractedData['metadata'])) {
            $output[] = "\n" . str_repeat('─', 60) . "\n";
            $output[] = $this->formatSection('🔍 EXTRACTION METADATA', 'muted');
            $output[] = $this->formatMetadata($extractedData['metadata']);
        }
        
        return implode("\n", $output);
    }
    
    /**
     * Format document data with clear labeling
     */
    private function formatDocumentData(array $data): string
    {
        $output = [];
        
        // Vehicle data from document
        if (!empty($data['vehicle'])) {
            $output[] = $this->formatSubsection('🚗 Vehicle (from document):');
            foreach ($data['vehicle'] as $key => $value) {
                if (!str_contains($key, '_') && !in_array($key, ['database_match', 'database_id', 'validation'])) {
                    $output[] = $this->formatField($key, $value, 2, '✓');
                }
            }
        }
        
        // Shipping data from document
        if (!empty($data['shipping']) || !empty($data['shipment'])) {
            $output[] = "\n" . $this->formatSubsection('🚢 Shipping (from document):');
            $shippingData = array_merge($data['shipping'] ?? [], $data['shipment'] ?? []);
            $output[] = $this->formatNestedData($shippingData, 2, '✓');
        }
        
        // Contact data from document
        if (!empty($data['contact'])) {
            $output[] = "\n" . $this->formatSubsection('👤 Contact (from document):');
            foreach ($data['contact'] as $key => $value) {
                $output[] = $this->formatField($key, $value, 2, '✓');
            }
        }
        
        return implode("\n", $output);
    }
    
    /**
     * Format AI-enhanced data with warnings
     */
    private function formatAiEnhancedData(array $data): string
    {
        $output = [];
        
        foreach ($data as $source => $enhancedData) {
            $output[] = $this->formatSubsection("⚡ Enhanced from: " . ucfirst(str_replace('_', ' ', $source)));
            
            if (isset($enhancedData['enhanced_fields'])) {
                $output[] = $this->formatWarning("  ⚠️  These fields were NOT in the original document:", 2);
                foreach ($enhancedData['enhanced_fields'] as $field => $value) {
                    $output[] = $this->formatField($field, $value, 4, '➕');
                }
            } else {
                $output[] = $this->formatNestedData($enhancedData, 2, '➕');
            }
        }
        
        return implode("\n", $output);
    }
    
    /**
     * Format attribution summary
     */
    private function formatAttributionSummary(array $attribution): string
    {
        $output = [];
        
        // Document fields count
        $docFields = $attribution['document_fields'] ?? [];
        $aiFields = $attribution['ai_enhanced_fields'] ?? [];
        
        $output[] = sprintf(
            "📄 From Document: %d fields %s",
            count($docFields),
            count($docFields) > 0 ? '(' . implode(', ', array_slice($docFields, 0, 5)) . (count($docFields) > 5 ? '...' : '') . ')' : ''
        );
        
        $output[] = sprintf(
            "🤖 AI Generated: %d fields %s", 
            count($aiFields),
            count($aiFields) > 0 ? '(' . implode(', ', array_slice($aiFields, 0, 5)) . (count($aiFields) > 5 ? '...' : '') . ')' : ''
        );
        
        // Add visual indicator
        $docPercentage = count($docFields) + count($aiFields) > 0 
            ? round((count($docFields) / (count($docFields) + count($aiFields))) * 100) 
            : 100;
            
        $output[] = "\n" . $this->formatProgressBar($docPercentage, 'Document vs AI Data');
        
        return implode("\n", $output);
    }
    
    /**
     * Format nested data structure
     */
    private function formatNestedData(array $data, int $indent = 0, string $prefix = ''): string
    {
        $output = [];
        $spaces = str_repeat('  ', $indent);
        
        foreach ($data as $key => $value) {
            if (is_array($value) && !empty($value)) {
                $output[] = $spaces . $this->formatKey($key) . ':';
                $output[] = $this->formatNestedData($value, $indent + 1, $prefix);
            } elseif ($value !== null && $value !== '') {
                $output[] = $this->formatField($key, $value, $indent, $prefix);
            }
        }
        
        return implode("\n", $output);
    }
    
    /**
     * Format a single field
     */
    private function formatField(string $key, $value, int $indent = 0, string $prefix = ''): string
    {
        $spaces = str_repeat('  ', $indent);
        $formattedKey = $this->formatKey($key);
        $formattedValue = $this->formatValue($value);
        
        return sprintf('%s%s %s: %s', $spaces, $prefix, $formattedKey, $formattedValue);
    }
    
    /**
     * Format section headers
     */
    private function formatSection(string $title, string $type = 'default'): string
    {
        $styles = [
            'info' => '🔵',
            'warning' => '🟡',
            'success' => '🟢',
            'error' => '🔴',
            'muted' => '⚫',
            'default' => '⚪'
        ];
        
        $emoji = $styles[$type] ?? $styles['default'];
        return "\n{$emoji} **{$title}**\n" . str_repeat('=', strlen($title) + 4);
    }
    
    private function formatSubsection(string $title): string
    {
        return "**{$title}**";
    }
    
    private function formatKey(string $key): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $key));
    }
    
    private function formatValue($value): string
    {
        if (is_bool($value)) {
            return $value ? '✅ Yes' : '❌ No';
        }
        
        if (is_array($value)) {
            return json_encode($value, JSON_PRETTY_PRINT);
        }
        
        if (is_null($value) || $value === '') {
            return '⚪ Not specified';
        }
        
        return (string) $value;
    }
    
    private function formatNote(string $note): string
    {
        return "_ℹ️  {$note}_";
    }
    
    private function formatWarning(string $warning, int $indent = 0): string
    {
        $spaces = str_repeat('  ', $indent);
        return "{$spaces}**⚠️  {$warning}**";
    }
    
    private function formatSuccess(string $message): string
    {
        return "✅ {$message}";
    }
    
    private function formatProgressBar(int $percentage, string $label): string
    {
        $filled = round($percentage / 5);
        $empty = 20 - $filled;
        
        $bar = str_repeat('█', $filled) . str_repeat('░', $empty);
        
        return sprintf(
            "%s: [%s] %d%% from document",
            $label,
            $bar,
            $percentage
        );
    }
    
    /**
     * Format metadata section
     */
    private function formatMetadata(array $metadata): string
    {
        $output = [];
        
        // Strategy info
        if (isset($metadata['extraction_strategies'])) {
            $output[] = '📋 Extraction Methods Used: ' . implode(' → ', $metadata['extraction_strategies']);
        }
        
        // Confidence scores
        if (isset($metadata['confidence_scores'])) {
            $output[] = '📊 Confidence Scores:';
            foreach ($metadata['confidence_scores'] as $strategy => $score) {
                $output[] = sprintf('   • %s: %.1f%%', ucfirst($strategy), $score * 100);
            }
        }
        
        // Overall confidence with visual indicator
        if (isset($metadata['overall_confidence'])) {
            $confidence = $metadata['overall_confidence'];
            $indicator = $confidence >= 0.8 ? '🟢' : ($confidence >= 0.5 ? '🟡' : '🔴');
            $output[] = sprintf('%s Overall Confidence: %.1f%%', $indicator, $confidence * 100);
        }
        
        // Processing time
        if (isset($metadata['processing_time_ms'])) {
            $output[] = sprintf('⏱️  Processing Time: %.2fms', $metadata['processing_time_ms']);
        }
        
        return implode("\n", $output);
    }
}
