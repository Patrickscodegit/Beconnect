<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robaws_customer_portal_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('robaws_client_id');
            $table->string('source');
            $table->timestamps();

            $table->unique('user_id');
            $table->index('robaws_client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robaws_customer_portal_links');
    }
};
