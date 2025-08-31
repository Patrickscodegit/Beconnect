<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Fake both s3 and local disks
        Storage::fake('s3');
        Storage::fake('local');
        
        // Override default disk to s3 for these tests
        config(['filesystems.default' => 's3']);
    }

    /** @test */
    public function authenticated_user_can_upload_csv_file(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('vehicles.csv', 100);

        $response = $this->actingAs($user)
            ->postJson('/upload/csv', [
                'csv_file' => $file,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'path',
                    'original_name',
                    'size',
                    'temporary_url'
                ]
            ])
            ->assertJson([
                'success' => true,
                'message' => 'CSV file uploaded successfully',
            ]);

        $path = $response->json('data.path');
        $this->assertTrue(Storage::disk('s3')->exists($path));
        $this->assertStringContainsString('csv-imports/', $path);
    }

    /** @test */
    public function csv_upload_validates_file_type(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('not-a-csv.jpg');

        $response = $this->actingAs($user)
            ->postJson('/upload/csv', [
                'csv_file' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => [
                    'csv_file'
                ]
            ])
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed',
            ]);
    }

    /** @test */
    public function authenticated_user_can_upload_vehicle_image(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('car.jpg', 800, 600);

        $response = $this->actingAs($user)
            ->postJson('/upload/vehicle-image', [
                'image' => $file,
                'vin' => '1HGCM82633A123456',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'path',
                    'vin',
                    'original_name',
                    'size',
                    'temporary_url'
                ]
            ])
            ->assertJson([
                'success' => true,
                'message' => 'Vehicle image uploaded successfully',
                'data' => [
                    'vin' => '1HGCM82633A123456'
                ]
            ]);

        $path = $response->json('data.path');
        $this->assertTrue(Storage::disk('s3')->exists($path));
        $this->assertStringContainsString('vehicle-images/1HG/', $path);
    }

    /** @test */
    public function vehicle_image_upload_validates_vin_format(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('car.jpg');

        $response = $this->actingAs($user)
            ->postJson('/upload/vehicle-image', [
                'image' => $file,
                'vin' => 'INVALID_VIN', // Wrong length
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => [
                    'vin'
                ]
            ])
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed',
            ]);
    }

    /** @test */
    public function authenticated_user_can_upload_vehicle_document(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('manual.pdf', 500);

        $response = $this->actingAs($user)
            ->postJson('/upload/vehicle-document', [
                'document' => $file,
                'vin' => '1HGCM82633A123456',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'path',
                    'vin',
                    'original_name',
                    'size',
                    'temporary_url'
                ]
            ])
            ->assertJson([
                'success' => true,
                'message' => 'Vehicle document uploaded successfully',
                'data' => [
                    'vin' => '1HGCM82633A123456'
                ]
            ]);

        $path = $response->json('data.path');
        $this->assertTrue(Storage::disk('s3')->exists($path));
        $this->assertStringContainsString('vehicle-documents/1HG/', $path);
    }

    /** @test */
    public function authenticated_user_can_list_files(): void
    {
        $user = User::factory()->create();
        
        // Store some test files
        Storage::disk('s3')->put('test-dir/file1.txt', 'Content 1');
        Storage::disk('s3')->put('test-dir/file2.txt', 'Content 2');

        $response = $this->actingAs($user)
            ->getJson('/files?directory=test-dir');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'directory',
                    'files' => [
                        '*' => [
                            'path',
                            'name',
                            'size',
                            'last_modified',
                            'temporary_url'
                        ]
                    ],
                    'count'
                ]
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'directory' => 'test-dir',
                    'count' => 2
                ]
            ]);
    }

    /** @test */
    public function authenticated_user_can_delete_file(): void
    {
        $user = User::factory()->create();
        
        // Store a test file
        $path = 'test/delete-me.txt';
        Storage::disk('s3')->put($path, 'Content to delete');
        $this->assertTrue(Storage::disk('s3')->exists($path));

        $response = $this->actingAs($user)
            ->deleteJson('/files', [
                'path' => $path,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'File deleted successfully',
            ]);

        $this->assertFalse(Storage::disk('s3')->exists($path));
    }

    /** @test */
    public function file_delete_returns_404_for_nonexistent_file(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->deleteJson('/files', [
                'path' => 'nonexistent/file.txt',
            ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'File not found',
            ]);
    }

    /** @test */
    public function guest_cannot_access_file_operations(): void
    {
        $file = UploadedFile::fake()->create('test.csv');

        $this->postJson('/upload/csv', ['csv_file' => $file])
            ->assertStatus(401);

        $this->getJson('/files')
            ->assertStatus(401);

        $this->deleteJson('/files', ['path' => 'test.txt'])
            ->assertStatus(401);
    }
}
