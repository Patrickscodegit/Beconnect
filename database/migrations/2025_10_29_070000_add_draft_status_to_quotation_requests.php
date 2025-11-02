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
        // PostgreSQL-specific constraint modification
        // SQLite doesn't support check constraints in this way - skip for SQLite
        if (DB::getDriverName() === 'pgsql') {
            // Drop the existing check constraint
            DB::statement("
                ALTER TABLE quotation_requests 
                DROP CONSTRAINT IF EXISTS quotation_requests_status_check
            ");
            
            // Recreate the constraint with 'draft' added
            DB::statement("
                ALTER TABLE quotation_requests 
                ADD CONSTRAINT quotation_requests_status_check 
                CHECK (status::text = ANY (ARRAY['draft'::character varying, 'pending'::character varying, 'processing'::character varying, 'quoted'::character varying, 'accepted'::character varying, 'rejected'::character varying, 'expired'::character varying]::text[]))
            ");
        }
        // For SQLite, the status column is just a string - no constraint modification needed
        // The application code will validate the status values
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
            CHECK (status::text = ANY (ARRAY['pending'::character varying, 'processing'::character varying, 'quoted'::character varying, 'accepted'::character varying, 'rejected'::character varying, 'expired'::character varying]::text[]))
        ");
    }
};

