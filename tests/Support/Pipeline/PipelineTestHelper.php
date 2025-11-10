<?php

namespace Tests\Support\Pipeline;

use App\Services\DocumentService;
use App\Services\Extraction\HybridExtractionPipeline;
use App\Services\IntakeCreationService;
use App\Services\LlmExtractor;
use App\Services\OcrService;
use App\Services\PdfService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Pipeline\Fakes\FakeHybridExtractionPipeline;
use Tests\Support\Pipeline\Fakes\FakeIntakeCreationService;
use Tests\Support\Pipeline\Fakes\FakeLlmExtractor;
use Tests\Support\Pipeline\Fakes\FakeOcrService;
use Tests\Support\Pipeline\Fakes\FakePdfService;

class PipelineTestHelper
{
    private static bool $fakesBound = false;
    private static bool $environmentConfigured = false;

    public static function prepare(): void
    {
        if (self::$environmentConfigured) {
            return;
        }

        $dbPath = dirname(__DIR__, 3) . '/database/pipeline-testing.sqlite';

        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=' . $dbPath);
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $dbPath;
        $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_SERVER['DB_DATABASE'] = $dbPath;

        self::$environmentConfigured = true;
    }

    public static function boot($test): void
    {
        if (!env('PIPELINE_TESTS', false)) {
            $test->markTestSkipped('Set PIPELINE_TESTS=true to run pipeline integration tests.');
        }

        $dbPath = $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? null;

        if ($dbPath) {
            $db = app('db');
            $db->connection('sqlite')->disconnect();
            $db->purge('sqlite');

            if (file_exists($dbPath)) {
                @unlink($dbPath);
            }
            touch($dbPath);

            config([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => $dbPath,
            ]);

            $db->setDefaultConnection('sqlite');

            Artisan::call('migrate', [
                '--env' => 'testing',
                '--force' => true,
            ]);
        }

        if (!self::$fakesBound) {
            self::fakeServices();
            self::$fakesBound = true;
        }
    }

    private static function fakeServices(): void
    {
        App::forgetInstance(PdfService::class);
        app()->singleton(PdfService::class, fn () => new FakePdfService());

        App::forgetInstance(OcrService::class);
        app()->singleton(OcrService::class, fn () => new FakeOcrService());

        App::forgetInstance(LlmExtractor::class);
        app()->singleton(LlmExtractor::class, fn () => new FakeLlmExtractor());

        App::forgetInstance(HybridExtractionPipeline::class);
        app()->singleton(HybridExtractionPipeline::class, fn () => new FakeHybridExtractionPipeline());

        App::forgetInstance(IntakeCreationService::class);
        app()->singleton(IntakeCreationService::class, fn () => new FakeIntakeCreationService());

        App::forgetInstance(DocumentService::class);
    }
}

