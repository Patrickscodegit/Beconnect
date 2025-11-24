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
        Schema::table('article_children', function (Blueprint $table) {
            $table->enum('child_type', ['mandatory', 'optional', 'conditional'])
                ->default('optional')
                ->after('is_conditional');
        });

        // Update existing records based on is_required and is_conditional
        DB::table('article_children')->get()->each(function ($record) {
            $childType = 'optional';
            
            if ($record->is_required && !$record->is_conditional) {
                $childType = 'mandatory';
            } elseif (!$record->is_required && $record->is_conditional) {
                $childType = 'conditional';
            }
            
            DB::table('article_children')
                ->where('parent_article_id', $record->parent_article_id)
                ->where('child_article_id', $record->child_article_id)
                ->update(['child_type' => $childType]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('article_children', function (Blueprint $table) {
            $table->dropColumn('child_type');
        });
    }
};
