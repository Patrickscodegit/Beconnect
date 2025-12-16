#!/bin/bash

# Production VAT Issue Diagnostic Script
# Run this on production server to check why VAT is not exempt

echo "🔍 Checking VAT Issue in Production..."
echo "=================================================="

echo ""
echo "1️⃣ CHECK LATEST QUOTATION VAT SETTINGS:"
echo "----------------------------------------"
php artisan tinker --execute="
\$qr = \App\Models\QuotationRequest::latest()->first();
if (\$qr) {
    echo 'Quotation ID: ' . \$qr->id . PHP_EOL;
    echo 'Request Number: ' . \$qr->request_number . PHP_EOL;
    echo 'POL: ' . (\$qr->pol ?? 'NULL') . PHP_EOL;
    echo 'POD: ' . (\$qr->pod ?? 'NULL') . PHP_EOL;
    echo 'project_vat_code: ' . (\$qr->project_vat_code ?? 'NULL') . PHP_EOL;
    echo 'vat_rate: ' . (\$qr->vat_rate ?? 'NULL') . PHP_EOL;
    echo 'vat_amount: ' . (\$qr->vat_amount ?? 'NULL') . PHP_EOL;
    echo 'total_excl_vat: ' . (\$qr->total_excl_vat ?? 'NULL') . PHP_EOL;
    echo 'total_incl_vat: ' . (\$qr->total_incl_vat ?? 'NULL') . PHP_EOL;
    echo PHP_EOL;
    echo 'Effective VAT Rate (accessor): ' . \$qr->effective_vat_rate . PHP_EOL;
    echo 'VAT Label (accessor): ' . \$qr->vat_label . PHP_EOL;
} else {
    echo 'No quotations found' . PHP_EOL;
}
"

echo ""
echo "2️⃣ CHECK VAT RESOLVER LOGIC FOR LATEST QUOTATION:"
echo "--------------------------------------------------"
php artisan tinker --execute="
\$qr = \App\Models\QuotationRequest::latest()->first();
if (\$qr) {
    \$resolver = app(\App\Services\Pricing\VatResolverInterface::class);
    \$euChecker = app(\App\Services\Pricing\EuCountryChecker::class);
    
    echo 'Testing VAT Resolution:' . PHP_EOL;
    echo 'POL: ' . (\$qr->pol ?? 'NULL') . PHP_EOL;
    echo 'POD: ' . (\$qr->pod ?? 'NULL') . PHP_EOL;
    
    \$originIso = \$euChecker->getCountryIsoFromPortString(\$qr->pol);
    \$destIso = \$euChecker->getCountryIsoFromPortString(\$qr->pod);
    
    echo 'Origin ISO: ' . (\$originIso ?? 'NULL') . PHP_EOL;
    echo 'Destination ISO: ' . (\$destIso ?? 'NULL') . PHP_EOL;
    echo 'Is EU Origin: ' . (\$euChecker->isEuCountry(\$originIso) ? 'YES' : 'NO') . PHP_EOL;
    echo 'Is EU Destination: ' . (\$euChecker->isEuCountry(\$destIso) ? 'YES' : 'NO') . PHP_EOL;
    
    \$projectVatCode = \$resolver->determineProjectVatCode(\$qr);
    echo 'Determined project_vat_code: ' . \$projectVatCode . PHP_EOL;
    echo 'Current project_vat_code in DB: ' . (\$qr->project_vat_code ?? 'NULL') . PHP_EOL;
    
    if (\$projectVatCode !== \$qr->project_vat_code) {
        echo PHP_EOL . '⚠️  MISMATCH: Resolver says ' . \$projectVatCode . ' but DB has ' . (\$qr->project_vat_code ?? 'NULL') . PHP_EOL;
    }
} else {
    echo 'No quotations found' . PHP_EOL;
}
"

echo ""
echo "3️⃣ CHECK LARAVEL LOGS FOR VAT-RELATED ERRORS:"
echo "----------------------------------------------"
if [ -f "storage/logs/laravel.log" ]; then
    echo "📋 Last 100 lines containing 'VAT' or 'vat' or 'project_vat_code':"
    tail -1000 storage/logs/laravel.log | grep -i -E "vat|project_vat_code" | tail -50
else
    echo "❌ Laravel log file not found"
fi

echo ""
echo "4️⃣ CHECK IF MIGRATION WAS RUN:"
echo "-------------------------------"
php artisan tinker --execute="
if (\Illuminate\Support\Facades\Schema::hasColumn('quotation_requests', 'project_vat_code')) {
    echo '✅ project_vat_code column exists' . PHP_EOL;
} else {
    echo '❌ project_vat_code column does NOT exist - migration not run!' . PHP_EOL;
}

if (\Illuminate\Support\Facades\Schema::hasColumn('quotation_request_articles', 'vat_code')) {
    echo '✅ vat_code column exists' . PHP_EOL;
} else {
    echo '❌ vat_code column does NOT exist - migration not run!' . PHP_EOL;
}
"

echo ""
echo "5️⃣ CHECK OBSERVER REGISTRATION:"
echo "--------------------------------"
php artisan tinker --execute="
\$observers = \App\Providers\AppServiceProvider::class;
echo 'Checking if QuotationRequestObserver is registered...' . PHP_EOL;
\$reflection = new ReflectionClass(\App\Observers\QuotationRequestObserver::class);
echo 'Observer class exists: ' . (\$reflection ? 'YES' : 'NO') . PHP_EOL;
"

echo ""
echo "=================================================="
echo "✅ Diagnostic complete!"
echo ""
echo "Next steps:"
echo "1. If project_vat_code column doesn't exist, run: php artisan migrate"
echo "2. If project_vat_code is NULL, check if observer is running"
echo "3. If there's a mismatch, recalculate VAT: php artisan tinker --execute=\"\$qr = \App\Models\QuotationRequest::latest()->first(); app(\App\Services\Pricing\QuotationVatService::class)->recalculateVatForQuotation(\$qr);\""

