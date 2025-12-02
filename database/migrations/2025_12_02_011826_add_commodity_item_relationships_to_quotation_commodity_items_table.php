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
        Schema::table('quotation_commodity_items', function (Blueprint $table) {
            // Add relationship type enum
            $table->enum('relationship_type', ['separate', 'connected_to', 'loaded_with'])
                ->default('separate')
                ->after('quotation_request_id');
            
            // Add foreign key to related item (self-referential)
            $table->foreignId('related_item_id')
                ->nullable()
                ->after('relationship_type')
                ->constrained('quotation_commodity_items')
                ->onDelete('set null');
            
            // Add indexes for performance
            $table->index('relationship_type');
            $table->index('related_item_id');
            $table->index(['quotation_request_id', 'relationship_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotation_commodity_items', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex(['quotation_request_id', 'relationship_type']);
            $table->dropIndex(['related_item_id']);
            $table->dropIndex(['relationship_type']);
            
            // Drop foreign key constraint
            $table->dropForeign(['related_item_id']);
            
            // Drop columns
            $table->dropColumn(['relationship_type', 'related_item_id']);
        });
    }
};
