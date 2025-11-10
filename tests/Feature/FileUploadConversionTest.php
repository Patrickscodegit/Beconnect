<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Intake;
use Livewire\Livewire;
use Livewire\Features\SupportTesting\Testable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Pipeline\PipelineTestHelper;

/** @group pipeline */
class FileUploadConversionTest extends TestCase
{
    protected function setUp(): void
    {
        PipelineTestHelper::prepare();
        parent::setUp();

        PipelineTestHelper::boot($this);
        
        // Create a test user for Filament authentication
        $user = User::factory()->create();
        $this->actingAs($user);
    }

    /** @test */
    public function it_converts_temporary_uploaded_file_to_uploaded_file()
    {
        Storage::fake('local');
        Queue::fake();
        
        // Create a fake PDF file
        $file = UploadedFile::fake()->create('test-document.pdf', 100, 'application/pdf');
        
        Livewire::test(\App\Filament\Resources\IntakeResource\Pages\CreateIntake::class)
            ->set('data.status', 'pending')
            ->set('data.source', 'upload')
            ->set('data.priority', 'normal')
            ->set('data.service_type', 'RORO_EXPORT')
            ->set('data.intake_files', [$file])
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('intakes', 1);
        
        $intake = Intake::first();
        $this->assertContains($intake->status, ['pending', 'processing', 'needs_contact']);
        $this->assertContains($intake->source, ['upload', 'manual_upload']);
        
        $this->assertGreaterThan(0, $intake->files()->count());
    }

    /** @test */
    public function it_handles_multiple_file_uploads()
    {
        Storage::fake('local');
        Queue::fake();
        
        $files = [
            UploadedFile::fake()->create('document1.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('image1.jpg', 50, 'image/jpeg'),
            UploadedFile::fake()->create('document2.pdf', 75, 'application/pdf'),
        ];
        
        Livewire::test(\App\Filament\Resources\IntakeResource\Pages\CreateIntake::class)
            ->set('data.status', 'pending')
            ->set('data.source', 'upload')
            ->set('data.priority', 'normal')
            ->set('data.service_type', 'RORO_EXPORT')
            ->set('data.intake_files', $files)
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('intakes', 1);
        
        $intake = Intake::first();
        $this->assertEquals(3, $intake->files()->count());
    }

    /** @test */
    public function it_handles_empty_file_upload_gracefully()
    {
        Livewire::test(\App\Filament\Resources\IntakeResource\Pages\CreateIntake::class)
            ->set('data.status', 'pending')
            ->set('data.source', 'upload')
            ->set('data.priority', 'normal')
            ->set('data.service_type', 'RORO_EXPORT')
            ->set('data.intake_files', [])
            ->call('create')
            ->assertHasNoErrors();

        // Should create intake without files
        $this->assertDatabaseCount('intakes', 1);
        
        $intake = Intake::first();
        $this->assertEquals(0, $intake->files()->count());
    }

    /** @test */
    public function it_validates_file_types()
    {
        Storage::fake('local');
        
        // Try to upload an unsupported file type
        $file = UploadedFile::fake()->create('document.txt', 10, 'text/plain');
        
        Livewire::test(\App\Filament\Resources\IntakeResource\Pages\CreateIntake::class)
            ->set('data.status', 'pending')
            ->set('data.source', 'upload')
            ->set('data.priority', 'normal')
            ->set('data.service_type', 'RORO_EXPORT')
            ->set('data.intake_files', [$file])
            ->call('create')
            ->assertHasErrors(['data.intake_files']);
    }

    /** @test */
    public function it_processes_contact_information_from_form()
    {
        Storage::fake('local');
        Queue::fake();
        
        $file = UploadedFile::fake()->create('test-document.pdf', 100, 'application/pdf');
        
        Livewire::test(\App\Filament\Resources\IntakeResource\Pages\CreateIntake::class)
            ->set('data.status', 'pending')
            ->set('data.source', 'upload')
            ->set('data.priority', 'normal')
            ->set('data.service_type', 'RORO_EXPORT')
            ->set('data.customer_name', 'John Doe')
            ->set('data.contact_email', 'john@example.com')
            ->set('data.contact_phone', '+1234567890')
            ->set('data.intake_files', [$file])
            ->call('create')
            ->assertHasNoErrors();

        $intake = Intake::first();
        $this->assertEquals('John Doe', $intake->customer_name);
        $this->assertEquals('john@example.com', $intake->contact_email);
        $this->assertEquals('+1234567890', $intake->contact_phone);
        
        $this->assertContains($intake->status, ['pending', 'processing']);
        
        Queue::assertPushed(\App\Jobs\ProcessIntake::class);
    }
}
