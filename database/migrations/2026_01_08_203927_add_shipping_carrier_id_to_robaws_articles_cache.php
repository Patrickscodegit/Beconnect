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
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            if (!Schema::hasColumn('robaws_articles_cache', 'shipping_carrier_id')) {
                $table->foreignId('shipping_carrier_id')
                      ->nullable()
                      ->after('shipping_line')
                      ->constrained('shipping_carriers')
                      ->onDelete('set null');
                
                $table->index('shipping_carrier_id');
            }
        });
        
        // Data migration: Match shipping_line strings to shipping_carriers
        // Use PHP loop for SQLite compatibility (SQLite doesn't support aliases in UPDATE)
        $articles = DB::table('robaws_articles_cache')
            ->whereNotNull('shipping_line')
            ->whereNull('shipping_carrier_id')
            ->get(['id', 'shipping_line']);
        
        $carriers = DB::table('shipping_carriers')
            ->get(['id', 'name', 'code']);
        
        foreach ($articles as $article) {
            $shippingLine = $article->shipping_line;
            $matchedCarrierId = null;
            $matchPriority = 999;
            
            foreach ($carriers as $carrier) {
                $priority = 999;
                
                // Exact name match (priority 1)
                if (strtolower($carrier->name) === strtolower($shippingLine)) {
                    $priority = 1;
                }
                // Partial name match - carrier name starts with shipping line (priority 2)
                elseif (stripos($carrier->name, $shippingLine) === 0) {
                    $priority = 2;
                }
                // Partial name match - shipping line starts with carrier name (priority 2)
                elseif (stripos($shippingLine, $carrier->name) === 0) {
                    $priority = 2;
                }
                // Code match (priority 3)
                elseif ($carrier->code && strtoupper($carrier->code) === strtoupper(substr($shippingLine, 0, 10))) {
                    $priority = 3;
                }
                
                if ($priority < $matchPriority) {
                    $matchPriority = $priority;
                    $matchedCarrierId = $carrier->id;
                }
            }
            
            if ($matchedCarrierId) {
                DB::table('robaws_articles_cache')
                    ->where('id', $article->id)
                    ->update(['shipping_carrier_id' => $matchedCarrierId]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('robaws_articles_cache', function (Blueprint $table) {
            $table->dropForeign(['shipping_carrier_id']);
            $table->dropIndex(['shipping_carrier_id']);
            $table->dropColumn('shipping_carrier_id');
        });
    }
};
