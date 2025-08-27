<?php

namespace Tests\Unit\Helpers;

use App\Helpers\FileInput;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileInputTest extends TestCase
{
    public function test_returns_bytes_for_local_storage()
    {
        // Mock local storage
        config(['filesystems.default' => 'local']);
        Storage::fake('local');
        
        // Create test file
        Storage::disk('local')->put('test/file.png', 'test content');
        
        // Get file input
        $result = FileInput::forExtractor('test/file.png', 'image/png');
        
        // Assert bytes format
        $this->assertArrayHasKey('bytes', $result);
        $this->assertArrayHasKey('mime', $result);
        $this->assertEquals(base64_encode('test content'), $result['bytes']);
        $this->assertEquals('image/png', $result['mime']);
    }
    
    public function test_throws_exception_for_missing_file()
    {
        // Mock local storage
        config(['filesystems.default' => 'local']);
        Storage::fake('local');
        
        // Expect exception for missing file
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File not found at path: missing/file.png');
        
        FileInput::forExtractor('missing/file.png', 'image/png');
    }
    
    public function test_returns_url_for_s3_storage()
    {
        // Mock S3 storage
        config(['filesystems.default' => 's3']);
        
        // Mock the Storage facade
        Storage::shouldReceive('disk')
            ->with('s3')
            ->andReturnSelf();
        Storage::shouldReceive('temporaryUrl')
            ->with('test/file.png', \Mockery::any())
            ->andReturn('https://example.com/signed-url');
        
        // Get file input
        $result = FileInput::forExtractor('test/file.png', 'image/png');
        
        // Assert URL format
        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('mime', $result);
        $this->assertEquals('https://example.com/signed-url', $result['url']);
        $this->assertEquals('image/png', $result['mime']);
    }
}
