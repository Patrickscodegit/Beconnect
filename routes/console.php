<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\AiRouter;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ai:test {text?}', function (string $text = 'Consignee: Belgaco Shipping Ltd, Address: Dubai Investment Park-1, Phone: +971-4-8859876') {
    /** @var AiRouter $ai */
    $ai = app(AiRouter::class);

    $this->info('🧪 Testing AI extraction pipeline...');
    $this->newLine();

    $schema = [
        'type' => 'object',
        'properties' => [
            'consignee' => ['type' => 'string'],
            'address'   => ['type' => 'string'],
            'phone'     => ['type' => 'string'],
            'email'     => ['type' => 'string'],
        ],
        'required' => ['consignee'],
        'additionalProperties' => false,
    ];

    $this->line("Input text: {$text}");
    $this->newLine();

    try {
        $startTime = microtime(true);

        $json = $ai->extract($text, $schema, [
            // Use cheap model for testing
            'cheap' => true,
            // Set false for simple extraction
            'reasoning' => false,
        ]);

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        $this->info("✅ AI extraction successful ({$duration}ms)");
        $this->newLine();
        $this->line('📋 Extracted JSON:');
        $this->line(json_encode($json, JSON_PRETTY_PRINT));

    } catch (\Exception $e) {
        $this->error('❌ AI extraction failed: ' . $e->getMessage());
        
        if (str_contains($e->getMessage(), 'API key')) {
            $this->warn('💡 Run: php artisan ai:configure');
        }
    }
})->describe('Test the AI extraction pipeline with sample freight data.');
