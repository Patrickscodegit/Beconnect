<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update sales_name for Waiver Burkina Faso
        $waiver = DB::table('robaws_articles_cache')
            ->where('article_name', 'Waiver Burkina Faso')
            ->first();

        if ($waiver) {
            DB::table('robaws_articles_cache')
                ->where('id', $waiver->id)
                ->update([
                    'sales_name' => 'Waiver / BESC (only applicable if in transit to Burkina Faso)',
                ]);
            
            Log::info('Updated Waiver Burkina Faso display name', [
                'article_id' => $waiver->id,
                'old_sales_name' => $waiver->sales_name,
                'new_sales_name' => 'Waiver / BESC (only applicable if in transit to Burkina Faso)',
            ]);
        } else {
            Log::warning('Waiver Burkina Faso not found when updating display name');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore original sales_name (set to null to use article_name)
        DB::table('robaws_articles_cache')
            ->where('article_name', 'Waiver Burkina Faso')
            ->update(['sales_name' => null]);
    }
};

