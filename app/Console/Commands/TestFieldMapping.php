<?php

namespace App\Console\Commands;

use App\Services\Robaws\RobawsFieldMapper;
use Illuminate\Console\Command;

class TestFieldMapping extends Command
{
    protected $signature = 'robaws:test-field-mapping';
    protected $description = 'Test the RobawsFieldMapper with sample extraFields data';

    public function handle()
    {
        $this->info('🧪 Testing RobawsFieldMapper with sample data...');
        $this->newLine();

        $fieldMapper = app(RobawsFieldMapper::class);

        // Test data simulating what we see in production
        $testExtraFields = [
            'PARENT_ITEM' => ['numberValue' => 1],  // Underscore format (actual)
            'SHIPPING LINE' => ['stringValue' => 'SALLAUM LINES'],  // Space format
            'SERVICE_TYPE' => ['stringValue' => 'RORO EXPORT'],  // Underscore format
            'POL TERMINAL' => ['stringValue' => 'ST 332'],  // Space format
        ];

        $this->info('📋 Sample extraFields data:');
        foreach ($testExtraFields as $key => $value) {
            $displayValue = $value['stringValue'] ?? $value['numberValue'] ?? $value['booleanValue'] ?? 'null';
            $this->line("  • {$key}: {$displayValue}");
        }
        $this->newLine();

        // Test each field mapping
        $tests = [
            'parent_item' => 'Parent Item (Boolean)',
            'shipping_line' => 'Shipping Line (String)',
            'service_type' => 'Service Type (String)', 
            'pol_terminal' => 'POL Terminal (String)',
        ];

        $this->info('🔍 Testing field mappings:');
        foreach ($tests as $canonicalField => $description) {
            $value = $fieldMapper->findFieldValue($testExtraFields, $canonicalField);
            $actualField = $fieldMapper->getActualFieldName($testExtraFields, $canonicalField);
            
            if ($value !== null) {
                $this->line("  ✅ {$description}");
                $this->line("     Found as: '{$actualField}' → Value: {$value}");
            } else {
                $this->line("  ❌ {$description}");
                $this->line("     Not found in extraFields");
            }
        }
        $this->newLine();

        // Test boolean conversion specifically
        $this->info('🔧 Testing boolean conversion for parent_item:');
        $parentValue = $fieldMapper->getBooleanValue($testExtraFields, 'parent_item');
        if ($parentValue !== null) {
            $this->line("  ✅ Parent Item Boolean: " . ($parentValue ? 'TRUE' : 'FALSE'));
            $this->line("     Expected: TRUE (since numberValue = 1)");
        } else {
            $this->line("  ❌ Parent Item Boolean: NULL (not found)");
        }
        $this->newLine();

        // Test with old format (spaces) to ensure backward compatibility
        $this->info('🔄 Testing backward compatibility with old format:');
        $oldFormatFields = [
            'PARENT ITEM' => ['numberValue' => 0],  // Space format (old)
            'SHIPPING_LINE' => ['stringValue' => 'ACL'],  // Underscore format
        ];

        $oldParentValue = $fieldMapper->getBooleanValue($oldFormatFields, 'parent_item');
        $oldShippingValue = $fieldMapper->getStringValue($oldFormatFields, 'shipping_line');

        $this->line("  • PARENT ITEM (space): " . ($oldParentValue !== null ? ($oldParentValue ? 'TRUE' : 'FALSE') : 'NULL'));
        $this->line("  • SHIPPING_LINE (underscore): " . ($oldShippingValue ?? 'NULL'));
        $this->newLine();

        $this->info('📊 Field Mapping Summary:');
        $mappings = $fieldMapper->getFieldMappings();
        foreach ($mappings as $canonical => $variations) {
            $this->line("  • {$canonical}: " . implode(', ', $variations));
        }
        $this->newLine();

        $this->info('✅ Field mapping test completed!');
        $this->info('💡 The mapper should now handle both PARENT_ITEM and PARENT ITEM formats.');
    }
}
