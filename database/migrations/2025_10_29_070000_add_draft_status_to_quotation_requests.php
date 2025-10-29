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
        // For PostgreSQL, we need to alter the enum type
        DB::statement("ALTER TYPE quotation_request_status ADD VALUE IF NOT EXISTS 'draft'");
        
        // Alternatively, if the above doesn't work, we can recreate the constraint
        // This is safer and works across all PostgreSQL versions
        DB::statement("
            ALTER TABLE quotation_requests 
            DROP CONSTRAINT IF EXISTS quotation_requests_status_check
        ");
        
        DB::statement("
            ALTER TABLE quotation_requests 
            ADD CONSTRAINT quotation_requests_status_check 
            CHECK (status IN ('draft', 'pending', 'processing', 'quoted', 'accepted', 'rejected', 'expired'))
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove draft status from constraint
        DB::statement("
            ALTER TABLE quotation_requests 
            DROP CONSTRAINT IF EXISTS quotation_requests_status_check
        ");
        
        DB::statement("
            ALTER TABLE quotation_requests 
            ADD CONSTRAINT quotation_requests_status_check 
            CHECK (status IN ('pending', 'processing', 'quoted', 'accepted', 'rejected', 'expired'))
        ");
    }
};

