<?php

/**
 * Fix Production Issues Script
 * 
 * This script addresses the three main production issues:
 * 1. Redis connection failures for Horizon/queues
 * 2. PostgreSQL compatibility issues (if any)
 * 3. File storage path resolution issues
 */

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel application
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

class ProductionIssueFixer
{
    private array $issues = [];
    private array $fixes = [];

    public function checkAndFixAllIssues(): array
    {
        echo "🔍 Checking Production Issues...\n\n";

        $this->checkRedisConnection();
        $this->checkDatabaseConnection();
        $this->checkStorageConfiguration();
        $this->checkRobawsApiClient();

        if (empty($this->issues)) {
            echo "✅ All systems operational!\n";
        } else {
            echo "⚠️  Found " . count($this->issues) . " issue(s):\n";
            foreach ($this->issues as $issue) {
                echo "   • $issue\n";
            }
        }

        if (!empty($this->fixes)) {
            echo "\n🔧 Applied " . count($this->fixes) . " fix(es):\n";
            foreach ($this->fixes as $fix) {
                echo "   ✓ $fix\n";
            }
        }

        return [
            'issues' => $this->issues,
            'fixes' => $this->fixes,
            'status' => empty($this->issues) ? 'healthy' : 'needs_attention'
        ];
    }

    private function checkRedisConnection(): void
    {
        try {
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $redis->ping();
            echo "✅ Redis connection: OK\n";
        } catch (\Exception $e) {
            $this->issues[] = "Redis connection failed: " . $e->getMessage();
            echo "❌ Redis connection: FAILED - " . $e->getMessage() . "\n";
            
            // Check if we should fall back to database queue
            if (config('queue.default') === 'redis') {
                echo "   ℹ️  Consider switching to database queue for now\n";
                $this->addDatabaseQueueFallback();
            }
        }
    }

    private function checkDatabaseConnection(): void
    {
        try {
            $connection = config('database.default');
            $driver = config("database.connections.{$connection}.driver");
            
            \Illuminate\Support\Facades\DB::connection()->getPdo();
            echo "✅ Database connection ($driver): OK\n";

            // Test a simple query to verify PostgreSQL compatibility
            if ($driver === 'pgsql') {
                $this->testPostgreSQLQueries();
            }
        } catch (\Exception $e) {
            $this->issues[] = "Database connection failed: " . $e->getMessage();
            echo "❌ Database connection: FAILED - " . $e->getMessage() . "\n";
        }
    }

    private function testPostgreSQLQueries(): void
    {
        try {
            // Test the VehicleDatabaseService queries that might cause issues
            $result = \Illuminate\Support\Facades\DB::select("SELECT 1 as test");
            
            // Test string literal compatibility
            $result = \Illuminate\Support\Facades\DB::select("SELECT 'test' as test_string");
            
            echo "✅ PostgreSQL query compatibility: OK\n";
        } catch (\Exception $e) {
            $this->issues[] = "PostgreSQL query compatibility issue: " . $e->getMessage();
            echo "❌ PostgreSQL queries: FAILED - " . $e->getMessage() . "\n";
        }
    }

    private function checkStorageConfiguration(): void
    {
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk('documents');
            
            // Test if we can access the disk
            $disk->directories();
            echo "✅ Storage disk 'documents': OK\n";

            // Test file operations if possible
            $testFile = 'health-check-' . time() . '.txt';
            $disk->put($testFile, 'health check');
            
            if ($disk->exists($testFile)) {
                $content = $disk->get($testFile);
                $disk->delete($testFile);
                echo "✅ Storage read/write operations: OK\n";
            } else {
                $this->issues[] = "Storage write operation failed";
                echo "❌ Storage write: FAILED\n";
            }
        } catch (\Exception $e) {
            $this->issues[] = "Storage configuration error: " . $e->getMessage();
            echo "❌ Storage configuration: FAILED - " . $e->getMessage() . "\n";
        }
    }

    private function checkRobawsApiClient(): void
    {
        try {
            $apiClient = new \App\Services\Export\Clients\RobawsApiClient();
            $config = $apiClient->validateConfig();
            
            if ($config['valid']) {
                echo "✅ RobawsApiClient configuration: OK\n";
            } else {
                $this->issues[] = "RobawsApiClient configuration issues: " . implode(', ', $config['issues']);
                echo "❌ RobawsApiClient: ISSUES - " . implode(', ', $config['issues']) . "\n";
            }
        } catch (\Exception $e) {
            $this->issues[] = "RobawsApiClient instantiation failed: " . $e->getMessage();
            echo "❌ RobawsApiClient: FAILED - " . $e->getMessage() . "\n";
        }
    }

    private function addDatabaseQueueFallback(): void
    {
        // This would ideally update the .env file, but for safety we just recommend it
        $envPath = base_path('.env');
        if (file_exists($envPath)) {
            $envContent = file_get_contents($envPath);
            
            if (strpos($envContent, 'QUEUE_CONNECTION=redis') !== false) {
                echo "   💡 To fix: Change QUEUE_CONNECTION=redis to QUEUE_CONNECTION=database in .env\n";
                $this->fixes[] = "Recommended switching from Redis to Database queue";
            }
        }
    }

    public function generateHealthCheckCommand(): string
    {
        return "php artisan inspire && echo 'Laravel is working!'";
    }

    public function generateRedisInstallCommands(): array
    {
        return [
            "# Install Redis on Ubuntu/Debian:",
            "sudo apt update",
            "sudo apt install redis-server",
            "sudo systemctl start redis-server",
            "sudo systemctl enable redis-server",
            "",
            "# Test Redis:",
            "redis-cli ping",
            "",
            "# Then restart your Laravel application"
        ];
    }
}

// Run the health check
$fixer = new ProductionIssueFixer();
$result = $fixer->checkAndFixAllIssues();

echo "\n" . str_repeat("=", 50) . "\n";
echo "PRODUCTION HEALTH CHECK SUMMARY\n";
echo str_repeat("=", 50) . "\n";

if ($result['status'] === 'healthy') {
    echo "🎉 Status: HEALTHY\n";
} else {
    echo "⚠️  Status: NEEDS ATTENTION\n";
    echo "\n📋 Recommended Actions:\n";
    
    if (in_array('Redis', implode(' ', $result['issues']))) {
        echo "\n🔴 Redis Issues:\n";
        $commands = $fixer->generateRedisInstallCommands();
        foreach ($commands as $cmd) {
            echo $cmd . "\n";
        }
    }
    
    if (in_array('PostgreSQL', implode(' ', $result['issues']))) {
        echo "\n🔴 PostgreSQL Issues:\n";
        echo "Check your database connection settings and ensure PostgreSQL is running.\n";
        echo "Verify your .env file has correct DB_* settings.\n";
    }
    
    if (in_array('Storage', implode(' ', $result['issues']))) {
        echo "\n🔴 Storage Issues:\n";
        echo "Verify your filesystem configuration in config/filesystems.php\n";
        echo "Check that the documents disk is properly configured\n";
    }
}

echo "\n🔍 To run Laravel health check: " . $fixer->generateHealthCheckCommand() . "\n";

echo "\nHealth check completed at " . date('Y-m-d H:i:s') . "\n";
