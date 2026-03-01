<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('carrier_surcharge_rules', function (Blueprint $table) {
            $table->string('loaded_cargo_mode', 20)
                ->default('IGNORE')
                ->after('event_code');
        });

        $carrierId = DB::table('shipping_carriers')
            ->where('code', 'GRIMALDI')
            ->value('id');

        if ($carrierId) {
            DB::table('carrier_surcharge_rules')
                ->where('carrier_id', $carrierId)
                ->whereIn('event_code', ['STACKED', 'PIGGYBACK', 'LOADED_CARGO'])
                ->update(['loaded_cargo_mode' => 'FREE']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carrier_surcharge_rules', function (Blueprint $table) {
            $table->dropColumn('loaded_cargo_mode');
        });
    }
};
