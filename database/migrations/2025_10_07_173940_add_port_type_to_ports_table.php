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
        Schema::table('ports', function (Blueprint $table) {
            $table->string('type')->default('both')->after('region'); // 'pol', 'pod', or 'both'
        });
        
        // Update existing ports to be POL only (Antwerp, Zeebrugge, Flushing)
        DB::table('ports')->whereIn('code', ['ANR', 'ZEE', 'FLU'])->update(['type' => 'pol']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ports', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
