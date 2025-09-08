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
        Schema::table('intakes', function (Blueprint $table) {
            // Add robaws_exported_at if it doesn't exist
            if (!Schema::hasColumn('intakes', 'robaws_exported_at')) {
                $table->dateTime('robaws_exported_at')->nullable()->after('robaws_offer_id');
            }
            
            // Ensure robaws_offer_id exists and is the correct type
            if (!Schema::hasColumn('intakes', 'robaws_offer_id')) {
                $table->unsignedBigInteger('robaws_offer_id')->nullable()->after('id');
            }
            
            // Add robaws_export_status if it doesn't exist
            if (!Schema::hasColumn('intakes', 'robaws_export_status')) {
                $table->string('robaws_export_status')->nullable()->after('robaws_exported_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('intakes', function (Blueprint $table) {
            if (Schema::hasColumn('intakes', 'robaws_exported_at')) {
                $table->dropColumn('robaws_exported_at');
            }
            if (Schema::hasColumn('intakes', 'robaws_export_status')) {
                $table->dropColumn('robaws_export_status');
            }
            // Don't drop robaws_offer_id unless you're sure
        });
    }
};
