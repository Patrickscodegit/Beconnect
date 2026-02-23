<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('customer');
            $table->string('status')->default('pending');
        });

        $adminEmails = array_filter(array_map(
            'trim',
            explode(',', (string) env('ADMIN_EMAILS', ''))
        ));

        if (! empty($adminEmails)) {
            DB::table('users')
                ->whereIn('email', $adminEmails)
                ->update([
                    'role' => 'admin',
                    'status' => 'active',
                ]);
        } else {
            $firstUserId = DB::table('users')->orderBy('id')->value('id');
            if ($firstUserId) {
                DB::table('users')
                    ->where('id', $firstUserId)
                    ->update([
                        'role' => 'admin',
                        'status' => 'active',
                    ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'status']);
        });
    }
};
