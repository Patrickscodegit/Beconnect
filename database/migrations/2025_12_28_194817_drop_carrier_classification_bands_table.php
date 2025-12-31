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
        Schema::dropIfExists('carrier_classification_bands');
        // Note: GIN indexes are automatically dropped when table is dropped
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: Cannot recreate table as migrations have been deleted
        // If rollback is needed, restore from backup or re-run original migrations
    }
};
