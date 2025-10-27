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
        Schema::create('pricing_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 1)->unique()->comment('Tier code: A, B, C, etc.');
            $table->string('name', 100)->comment('Display name: Best Price, Medium Price, etc.');
            $table->text('description')->nullable()->comment('Usage guidelines for this tier');
            $table->decimal('margin_percentage', 5, 2)->comment('Margin % - can be negative for discounts (e.g., -5.00 = 5% discount, 15.00 = 15% markup)');
            $table->string('color', 20)->default('gray')->comment('Badge color: green, yellow, red, etc.');
            $table->string('icon', 10)->nullable()->comment('Emoji icon for display: 🟢, 🟡, 🔴');
            $table->integer('sort_order')->default(0)->comment('Display order in dropdowns');
            $table->boolean('is_active')->default(true)->comment('Only active tiers can be selected');
            $table->timestamps();
            
            // Indexes
            $table->index('code');
            $table->index('is_active');
            $table->index('sort_order');
        });
        
        // Seed initial 3 tiers
        DB::table('pricing_tiers')->insert([
            [
                'code' => 'A',
                'name' => 'Best Price',
                'description' => 'Preferred customers, high volume, freight forwarders, partners. Competitive pricing with small margin or discount.',
                'margin_percentage' => -5.00, // 5% DISCOUNT
                'color' => 'green',
                'icon' => '🟢',
                'sort_order' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'B',
                'name' => 'Medium Price',
                'description' => 'Standard customers, regular business, consignees. Standard margin for sustainable business.',
                'margin_percentage' => 15.00, // 15% MARKUP
                'color' => 'yellow',
                'icon' => '🟡',
                'sort_order' => 2,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'C',
                'name' => 'Expensive Price',
                'description' => 'Premium service, risky customers, special handling, blacklisted. High margin for risk mitigation.',
                'margin_percentage' => 25.00, // 25% MARKUP
                'color' => 'red',
                'icon' => '🔴',
                'sort_order' => 3,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_tiers');
    }
};

