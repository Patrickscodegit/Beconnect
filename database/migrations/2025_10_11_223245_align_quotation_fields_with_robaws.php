<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Align quotation fields with Robaws API structure:
     * - Client fields (company) map to Robaws Client API
     * - Contact fields (person) map to Robaws Contact API
     * 
     * Since we're in testing phase, we directly rename requester_* to contact_*
     */
    public function up(): void
    {
        Schema::table('quotation_requests', function (Blueprint $table) {
            // Add new Client fields (maps to Robaws Client API)
            $table->string('client_name')->nullable()->after('requester_company');
            $table->string('client_email')->nullable()->after('client_name');
            $table->string('client_tel')->nullable()->after('client_email');
            $table->unsignedBigInteger('robaws_client_id')->nullable()->after('robaws_offer_number');
            
            // Rename requester_* to contact_* (maps to Robaws Contact API)
            $table->renameColumn('requester_name', 'contact_name');
            $table->renameColumn('requester_email', 'contact_email');
            $table->renameColumn('requester_phone', 'contact_phone');
            $table->renameColumn('requester_company', 'contact_company'); // Keep for reference
            
            // Add new contact field
            $table->string('contact_function')->nullable()->after('contact_phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotation_requests', function (Blueprint $table) {
            // Reverse the changes
            $table->renameColumn('contact_name', 'requester_name');
            $table->renameColumn('contact_email', 'requester_email');
            $table->renameColumn('contact_phone', 'requester_phone');
            $table->renameColumn('contact_company', 'requester_company');
            
            $table->dropColumn([
                'client_name',
                'client_email',
                'client_tel',
                'robaws_client_id',
                'contact_function',
            ]);
        });
    }
};
