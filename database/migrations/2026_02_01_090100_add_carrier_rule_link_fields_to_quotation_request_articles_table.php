<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_request_articles', function (Blueprint $table) {
            if (!Schema::hasColumn('quotation_request_articles', 'carrier_rule_applied')) {
                $table->boolean('carrier_rule_applied')->nullable()->after('notes');
            }
            if (!Schema::hasColumn('quotation_request_articles', 'carrier_rule_event_code')) {
                $table->string('carrier_rule_event_code')->nullable()->after('carrier_rule_applied');
            }
            if (!Schema::hasColumn('quotation_request_articles', 'carrier_rule_commodity_item_id')) {
                $table->foreignId('carrier_rule_commodity_item_id')
                    ->nullable()
                    ->constrained('quotation_commodity_items')
                    ->nullOnDelete()
                    ->after('carrier_rule_event_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotation_request_articles', function (Blueprint $table) {
            if (Schema::hasColumn('quotation_request_articles', 'carrier_rule_commodity_item_id')) {
                $table->dropConstrainedForeignId('carrier_rule_commodity_item_id');
            }
            if (Schema::hasColumn('quotation_request_articles', 'carrier_rule_event_code')) {
                $table->dropColumn('carrier_rule_event_code');
            }
            if (Schema::hasColumn('quotation_request_articles', 'carrier_rule_applied')) {
                $table->dropColumn('carrier_rule_applied');
            }
        });
    }
};
