<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $content = <<<'EOT'
Dear,

Thank you for the enquiry, please note our offer as follows:

${ROUTE_PHRASE}

Cargo: ${CARGO_DESCRIPTION}
EOT;

        DB::table('offer_templates')->updateOrInsert(
            ['template_code' => 'RORO_EXP_INTRO_PUBLIC_V1'],
            [
                'template_name' => 'RORO Export - Intro - Public v1',
                'template_type' => 'intro',
                'service_type' => 'RORO_EXPORT',
                'customer_type' => null,
                'content' => $content,
                'available_variables' => json_encode(['POL', 'POD', 'CARGO_DESCRIPTION']),
                'sort_order' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('offer_templates')
            ->where('template_code', 'RORO_EXP_INTRO_PUBLIC_V1')
            ->delete();
    }
};
