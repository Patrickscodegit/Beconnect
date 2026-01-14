<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('quotation_requests', 'pol_port_id')) {
                $table->foreignId('pol_port_id')->nullable()->constrained('ports')->nullOnDelete()->after('pol');
            }
            if (!Schema::hasColumn('quotation_requests', 'pod_port_id')) {
                $table->foreignId('pod_port_id')->nullable()->constrained('ports')->nullOnDelete()->after('pod');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotation_requests', function (Blueprint $table) {
            if (Schema::hasColumn('quotation_requests', 'pol_port_id')) {
                $table->dropConstrainedForeignId('pol_port_id');
            }
            if (Schema::hasColumn('quotation_requests', 'pod_port_id')) {
                $table->dropConstrainedForeignId('pod_port_id');
            }
        });
    }
};
