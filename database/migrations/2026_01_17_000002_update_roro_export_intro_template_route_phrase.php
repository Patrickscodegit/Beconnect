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

        DB::table('offer_templates')
            ->where('template_code', 'RORO_EXP_INTRO_PUBLIC_V1')
            ->update([
                'content' => $content,
                'available_variables' => json_encode(['ROUTE_PHRASE', 'CARGO_DESCRIPTION']),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $content = <<<'EOT'
Dear,

Thank you for the enquiry, please note our offer as follows:

Ex delivered terminal "${POL}" to CFR "${POD}"

Cargo: ${CARGO_DESCRIPTION}
EOT;

        DB::table('offer_templates')
            ->where('template_code', 'RORO_EXP_INTRO_PUBLIC_V1')
            ->update([
                'content' => $content,
                'available_variables' => json_encode(['POL', 'POD', 'CARGO_DESCRIPTION']),
                'updated_at' => now(),
            ]);
    }
};
