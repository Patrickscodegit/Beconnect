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
        // Skip safely if the table doesn't exist (e.g., test DB)
        if (! Schema::hasTable('intakes')) {
            return;
        }

        Schema::table('intakes', function (Blueprint $table) {
            if (! Schema::hasColumn('intakes', 'export_status')) {
                $table->string('export_status')->nullable()->after('processing_status');
            }
            
            if (! Schema::hasColumn('intakes', 'exported_at')) {
                $table->timestamp('exported_at')->nullable()->after('export_status');
            }
            
            if (! Schema::hasColumn('intakes', 'export_notes')) {
                $table->text('export_notes')->nullable()->after('exported_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('intakes')) {
            return;
        }

        Schema::table('intakes', function (Blueprint $table) {
            $drops = array_filter([
                Schema::hasColumn('intakes', 'export_status') ? 'export_status' : null,
                Schema::hasColumn('intakes', 'exported_at') ? 'exported_at' : null,
                Schema::hasColumn('intakes', 'export_notes') ? 'export_notes' : null,
            ]);

            if ($drops) {
                $table->dropColumn($drops);
            }
        });
    }
};