<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schedule_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('sync_type')->default('manual'); // manual, automatic, scheduled
            $table->integer('schedules_updated')->default(0);
            $table->integer('carriers_processed')->default(0);
            $table->string('status', 50)->default('success'); // success, error, partial
            $table->text('error_message')->nullable();
            $table->json('details')->nullable(); // Additional sync details
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->index(['sync_type', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_sync_logs');
    }
};
