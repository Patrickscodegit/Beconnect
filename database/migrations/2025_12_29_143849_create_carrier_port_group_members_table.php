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
        Schema::create('carrier_port_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_port_group_id')->constrained('carrier_port_groups')->onDelete('cascade');
            $table->foreignId('port_id')->constrained('ports')->onDelete('cascade');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->unique(['carrier_port_group_id', 'port_id']);
            $table->index(['carrier_port_group_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_port_group_members');
    }
};
