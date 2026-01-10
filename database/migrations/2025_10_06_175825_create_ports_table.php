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
        Schema::create('ports', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 10)->unique();
            $table->string('country', 100)->nullable();
            $table->string('region', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     * 
     * ⚠️ WARNING: This will delete ALL ports data!
     * 
     * Before running this migration down, ensure:
     * 1. You have a backup of the ports table
     * 2. You understand the impact on dependent data (carriers, schedules, articles)
     * 3. You have a plan to restore or re-seed ports after rollback
     * 
     * To backup ports before rollback:
     * php artisan tinker --execute="file_put_contents('ports_backup.json', json_encode(App\Models\Port::all()->toArray()));"
     * 
     * To restore ports after rollback:
     * php artisan db:seed --class=PortSeeder
     * php artisan ports:sync-from-articles
     */
    public function down(): void
    {
        // ⚠️ SAFETY CHECK: In production, require explicit confirmation
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'Cannot drop ports table in production. ' .
                'If you really need to rollback, set APP_ENV to something other than production temporarily, ' .
                'and ensure you have a backup first.'
            );
        }
        
        Schema::dropIfExists('ports');
    }
};