<div class="space-y-4">
    {{-- Original Template --}}
    <div>
        <h4 class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">
            Original Template (with variables)
        </h4>
        <div class="rounded-lg bg-gray-50 p-4 dark:bg-gray-800">
            <div class="prose prose-sm max-w-none dark:prose-invert">
                {!! nl2br(e($template->template_content)) !!}
            </div>
        </div>
    </div>

    {{-- Rendered Preview --}}
    <div>
        <h4 class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">
            Preview (with sample data)
        </h4>
        <div class="rounded-lg border-2 border-primary-200 bg-white p-4 dark:border-primary-800 dark:bg-gray-900">
            <div class="prose prose-sm max-w-none dark:prose-invert">
                {!! nl2br(e($rendered)) !!}
            </div>
        </div>
    </div>

    {{-- Variable Help --}}
    <div class="rounded-lg bg-info-50 p-4 dark:bg-info-950">
        <h4 class="mb-2 text-sm font-semibold text-info-900 dark:text-info-100">
            Available Variables
        </h4>
        <div class="grid gap-2 text-xs text-info-700 dark:text-info-300 sm:grid-cols-2 md:grid-cols-3">
            <div><code class="rounded bg-white px-1 dark:bg-gray-800">{customerName}</code> - Customer company name</div>
            <div><code class="rounded bg-white px-1 dark:bg-gray-800">{contactPersonName}</code> - Contact person</div>
            <div><code class="rounded bg-white px-1 dark:bg-gray-800">{customerEmail}</code> - Customer email</div>
            <div><code class="rounded bg-white px-1 dark:bg-gray-800">{serviceType}</code> - Service type</div>
            <div><code class="rounded bg-white px-1 dark:bg-gray-800">{por}</code> - Place of receipt</div>
            <div><code class="rounded bg-white px-1 dark:bg-gray-800">{pol}</code> - Port of loading</div>
            <div><code class="rounded bg-white px-1 dark:bg-gray-800">{pod}</code> - Port of discharge</div>
            <div><code class="rounded bg-white px-1 dark:bg-gray-800">{fdest}</code> - Final destination</div>
            <div><code class="rounded bg-white px-1 dark:bg-gray-800">{route}</code> - Complete route</div>
            <div><code class="rounded bg-white px-1 dark:bg-gray-800">{commodity}</code> - Commodity type</div>
            <div><code class="rounded bg-white px-1 dark:bg-gray-800">{subtotalAmount}</code> - Subtotal with currency</div>
            <div><code class="rounded bg-white px-1 dark:bg-gray-800">{totalAmount}</code> - Total with currency</div>
            <div><code class="rounded bg-white px-1 dark:bg-gray-800">{vatRate}</code> - VAT percentage</div>
            <div><code class="rounded bg-white px-1 dark:bg-gray-800">{today}</code> - Today's date</div>
            <div><code class="rounded bg-white px-1 dark:bg-gray-800">{validUntil}</code> - Validity date</div>
            <div><code class="rounded bg-white px-1 dark:bg-gray-800">{quotationDate}</code> - Quotation created date</div>
        </div>
    </div>
</div>

