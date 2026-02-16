<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('robaws_domain_mappings')) {
            Schema::create('robaws_domain_mappings', function (Blueprint $table) {
                $table->id();
                $table->string('domain')->unique();
                $table->string('robaws_client_id');
                $table->string('label')->nullable();
                $table->timestamps();
            });
        }

        DB::table('robaws_domain_mappings')->updateOrInsert(
            ['domain' => 'belgaco.be'],
            [
                'robaws_client_id' => '218',
                'label' => 'Belgaco Shipping',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('robaws_domain_mappings');
    }
};
