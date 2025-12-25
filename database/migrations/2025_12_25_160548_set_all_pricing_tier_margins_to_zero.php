<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Set all pricing tier margins to 0%
        DB::table('pricing_tiers')->update(['margin_percentage' => 0]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot reverse - margins were set to 0, original values unknown
        // Leave empty or set back to a default if needed
    }
};
