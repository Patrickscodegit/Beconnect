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
        Schema::create('schedule_updates_log', function (Blueprint $table) {
            $table->id();
            $table->string('carrier_code', 10);
            $table->string('pol_code', 10);
            $table->string('pod_code', 10);
            $table->integer('schedules_found')->default(0);
            $table->integer('schedules_updated')->default(0);
            $table->integer('schedules_created')->default(0);
            $table->text('error_message')->nullable();
            $table->enum('status', ['success', 'partial', 'failed'])->default('success');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_updates_log');
    }
};