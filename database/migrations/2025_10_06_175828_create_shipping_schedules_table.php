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
        Schema::create('shipping_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained('shipping_carriers');
            $table->foreignId('pol_id')->constrained('ports');
            $table->foreignId('pod_id')->constrained('ports');
            $table->string('service_name', 100)->nullable();
            $table->decimal('frequency_per_week', 3, 1)->nullable();
            $table->decimal('frequency_per_month', 3, 1)->nullable();
            $table->integer('transit_days')->nullable();
            $table->string('vessel_name', 100)->nullable();
            $table->string('vessel_class', 50)->nullable();
            $table->date('ets_pol')->nullable();
            $table->date('eta_pod')->nullable();
            $table->date('next_sailing_date')->nullable(); // Crucial for next sailing
            $table->timestamp('last_updated')->useCurrent();
            $table->boolean('is_active')->default(true);
            $table->unique(['carrier_id', 'pol_id', 'pod_id', 'service_name']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_schedules');
    }
};