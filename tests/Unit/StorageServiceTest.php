<?php

use App\Services\StorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageServiceTest extends TestCase
{
    private StorageService $storageService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageService = new StorageService();
    }

    /** @test */
    public function it_can_store_a_file(): void
    {
        Storage::fake('s3');

        $file = UploadedFile::fake()->create('test.csv', 100);
        $path = $this->storageService->store($file, 'test-directory');

        $this->assertTrue(Storage::disk('s3')->exists($path));
        $this->assertStringContainsString('test-directory/', $path);
    }

    /** @test */
    public function it_can_store_csv_file(): void
    {
        Storage::fake('s3');

        $file = UploadedFile::fake()->create('vehicles.csv', 100);
        $path = $this->storageService->storeCsv($file);

        $this->assertTrue(Storage::disk('s3')->exists($path));
        $this->assertStringContainsString('csv-imports/', $path);
    }

    /** @test */
    public function it_can_store_vehicle_image(): void
    {
        Storage::fake('s3');

        $file = UploadedFile::fake()->image('car.jpg', 800, 600);
        $vin = '1HGCM82633A123456';
        $path = $this->storageService->storeVehicleImage($file, $vin);

        $this->assertTrue(Storage::disk('s3')->exists($path));
        $this->assertStringContainsString('vehicle-images/1HG/', $path);
    }

    /** @test */
    public function it_can_store_vehicle_document(): void
    {
        Storage::fake('s3');

        $file = UploadedFile::fake()->create('manual.pdf', 500);
        $vin = '1HGCM82633A123456';
        $path = $this->storageService->storeVehicleDocument($file, $vin);

        $this->assertTrue(Storage::disk('s3')->exists($path));
        $this->assertStringContainsString('vehicle-documents/1HG/', $path);
    }

    /** @test */
    public function it_can_store_and_retrieve_content(): void
    {
        Storage::fake('s3');

        $content = 'Test file content';
        $path = 'test/content.txt';
        
        $this->assertTrue($this->storageService->storeContent($content, $path));
        $this->assertEquals($content, $this->storageService->get($path));
    }

    /** @test */
    public function it_can_check_if_file_exists(): void
    {
        Storage::fake('s3');

        $content = 'Test content';
        $path = 'test/exists.txt';
        
        $this->assertFalse($this->storageService->exists($path));
        $this->storageService->storeContent($content, $path);
        $this->assertTrue($this->storageService->exists($path));
    }

    /** @test */
    public function it_can_delete_files(): void
    {
        Storage::fake('s3');

        $content = 'Test content';
        $path = 'test/delete-me.txt';
        
        $this->storageService->storeContent($content, $path);
        $this->assertTrue($this->storageService->exists($path));
        
        $this->assertTrue($this->storageService->delete($path));
        $this->assertFalse($this->storageService->exists($path));
    }

    /** @test */
    public function it_can_list_files_in_directory(): void
    {
        Storage::fake('s3');

        $this->storageService->storeContent('Content 1', 'test-dir/file1.txt');
        $this->storageService->storeContent('Content 2', 'test-dir/file2.txt');
        
        $files = $this->storageService->files('test-dir');
        
        $this->assertCount(2, $files);
        $this->assertContains('test-dir/file1.txt', $files);
        $this->assertContains('test-dir/file2.txt', $files);
    }

    /** @test */
    public function it_can_copy_files(): void
    {
        Storage::fake('s3');

        $content = 'Original content';
        $originalPath = 'original.txt';
        $copyPath = 'copy.txt';
        
        $this->storageService->storeContent($content, $originalPath);
        $this->assertTrue($this->storageService->copy($originalPath, $copyPath));
        
        $this->assertTrue($this->storageService->exists($originalPath));
        $this->assertTrue($this->storageService->exists($copyPath));
        $this->assertEquals($content, $this->storageService->get($copyPath));
    }

    /** @test */
    public function it_can_move_files(): void
    {
        Storage::fake('s3');

        $content = 'Original content';
        $originalPath = 'original.txt';
        $newPath = 'moved.txt';
        
        $this->storageService->storeContent($content, $originalPath);
        $this->assertTrue($this->storageService->move($originalPath, $newPath));
        
        $this->assertFalse($this->storageService->exists($originalPath));
        $this->assertTrue($this->storageService->exists($newPath));
        $this->assertEquals($content, $this->storageService->get($newPath));
    }

    /** @test */
    public function it_can_switch_storage_disks(): void
    {
        Storage::fake('s3');
        Storage::fake('local');

        $file = UploadedFile::fake()->create('test.txt', 100);
        
        // Store on S3 (default)
        $pathS3 = $this->storageService->store($file, 'test');
        $this->assertTrue(Storage::disk('s3')->exists($pathS3));
        
        // Switch to local disk
        $pathLocal = $this->storageService->disk('local')->store($file, 'test');
        $this->assertTrue(Storage::disk('local')->exists($pathLocal));
    }
}
