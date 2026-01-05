<x-filament-panels::page>
    @php
        $matrix = $this->matrix;
        $ports = $matrix['ports'] ?? [];
        $categoryOrder = $matrix['category_order'] ?? ['CAR', 'SVAN', 'BVAN', 'LM'];
        $effectiveDate = $matrix['effective_date'] ?? null;
        $hasCongestion = $matrix['has_congestion'] ?? false;
        $hasIccm = $matrix['has_iccm'] ?? false;
        
        // Row definitions with callback pattern for conditional rows
        $rows = [
            ['label' => 'Seafreight', 'field' => 'base_freight_amount', 'unit_field' => 'base_freight_unit'],
            ['label' => 'BAF', 'field' => 'baf_amount', 'unit_field' => 'baf_unit'],
            ['label' => 'ETS', 'field' => 'ets_amount', 'unit_field' => 'ets_unit'],
            ['label' => 'Port additional', 'field' => 'port_additional_amount', 'unit_field' => 'port_additional_unit'],
            ['label' => 'Admin Fee', 'field' => 'admin_fxe_amount', 'unit_field' => 'admin_fxe_unit'],
            ['label' => 'THC', 'field' => 'thc_amount', 'unit_field' => 'thc_unit'],
            ['label' => 'Measurement costs', 'field' => 'measurement_costs_amount', 'unit_field' => 'measurement_costs_unit'],
            [
                'label' => 'Congestion surcharge',
                'field' => 'congestion_surcharge_amount',
                'unit_field' => 'congestion_surcharge_unit',
                'if' => fn($matrix) => (bool) ($matrix['has_congestion'] ?? false),
            ],
            [
                'label' => 'ICTN/ICCM',
                'field' => 'iccm_amount',
                'unit_field' => 'iccm_unit',
                'if' => fn($matrix) => (bool) ($matrix['has_iccm'] ?? false),
            ],
        ];
    @endphp

    <div class="space-y-6">
        {{-- Page Header --}}
        <div class="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-900">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                GRIMALDI BELGIUM — Purchase Rates Overview
            </h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                West Africa — Used Vehicles
            </p>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-500">
                Loadport: Antwerp
                @if($effectiveDate)
                    | Effective date: {{ $effectiveDate }}
                @endif
            </p>
            
            {{-- Bulk Date Setter --}}
            <div class="mt-4 flex items-center gap-4">
                <div class="flex items-center gap-2">
                    <label class="text-sm text-gray-700 dark:text-gray-300">Update Date (All):</label>
                    <input 
                        type="date" 
                        wire:model="bulkUpdateDate" 
                        class="rounded-md border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    />
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-sm text-gray-700 dark:text-gray-300">Validity Date (All):</label>
                    <input 
                        type="date" 
                        wire:model="bulkValidityDate" 
                        class="rounded-md border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    />
                </div>
                <x-filament::button wire:click="applyBulkDates" size="sm">
                    Apply to All Destinations
                </x-filament::button>
                <x-filament::button 
                    wire:click="clearBulkDates" 
                    size="sm"
                    color="gray"
                    title="Clear all date overrides">
                    Clear All
                </x-filament::button>
            </div>
        </div>

        {{-- Port Blocks --}}
        @foreach($ports as $portCode => $portData)
            @php
                $portName = $portData['name'] ?? $portCode;
                $categories = $portData['categories'] ?? [];
            @endphp

            <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                {{-- Port Header with Save/Cancel --}}
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                        {{ $portCode }} — {{ $portName }}
                    </h2>
                    
                    <div class="flex items-center gap-2">
                        {{-- Per-Port Date Setter --}}
                        <input 
                            type="date" 
                            wire:model="portDates.{{ $portCode }}.update_date" 
                            class="rounded-md border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                            placeholder="Update Date"
                        />
                        <input 
                            type="date" 
                            wire:model="portDates.{{ $portCode }}.validity_date" 
                            class="rounded-md border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                            placeholder="Validity Date"
                        />
                        <x-filament::button wire:click="applyPortDates('{{ $portCode }}')" size="sm">
                            Apply to {{ $portCode }}
                        </x-filament::button>
                        <x-filament::button 
                            wire:click="clearPortDates('{{ $portCode }}')" 
                            size="sm"
                            color="gray"
                            title="Clear date overrides for {{ $portCode }}">
                            Clear
                        </x-filament::button>
                    </div>
                    
                    @if($this->hasDirtyForPort($portCode))
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                {{ count($this->getDirtyTariffIdsForPort($portCode)) }} changed
                            </span>
                            <x-filament::button 
                                size="sm"
                                wire:click="savePort('{{ $portCode }}')"
                                wire:loading.attr="disabled">
                                Save {{ $portCode }}
                            </x-filament::button>
                            <x-filament::button 
                                size="sm" 
                                color="gray"
                                wire:click="cancelPort('{{ $portCode }}')"
                                wire:loading.attr="disabled">
                                Cancel
                            </x-filament::button>
                        </div>
                    @endif
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Item</th>
                                <th class="px-3 py-2 text-center font-semibold text-gray-700 dark:text-gray-300">CAR</th>
                                <th class="px-3 py-2 text-center font-semibold text-gray-700 dark:text-gray-300">SVAN</th>
                                <th class="px-3 py-2 text-center font-semibold text-gray-700 dark:text-gray-300">BVAN</th>
                                <th class="px-3 py-2 text-center font-semibold text-gray-700 dark:text-gray-300">RORO/LM</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                @php
                                    // Skip conditional rows if 'if' callback returns false
                                    if (isset($row['if']) && is_callable($row['if']) && !$row['if']($matrix)) {
                                        continue;
                                    }
                                @endphp
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="px-3 py-2 font-medium text-gray-700 dark:text-gray-300">
                                        {{ $row['label'] }}
                                    </td>
                                    
                                    @foreach($categoryOrder as $category)
                                        @php
                                            $categoryData = $categories[$category] ?? null;
                                            $tariffId = $categoryData['tariff_id'] ?? null;
                                            $tariff = $categoryData['tariff'] ?? null;
                                            $editUrl = $categoryData['edit_url'] ?? null;
                                            
                                            $field = $row['field'] ?? null;
                                            $unitField = $row['unit_field'] ?? null;
                                            
                                            // Determine if this cell is being edited
                                            $isEditing = $tariffId && $field && $this->editingKey === "{$tariffId}:{$field}";
                                            
                                            // Determine if this cell has dirty changes
                                            $isDirty = $tariffId && $field && isset($this->dirty[$tariffId][$field]);
                                            
                                            // Get amount (dirty or original)
                                            if ($isDirty && $tariffId) {
                                                $dirtyValue = $this->dirty[$tariffId][$field];
                                                $amount = $this->normalizeNumeric($dirtyValue);
                                            } elseif ($tariff) {
                                                $amount = $tariff->getAttribute($field);
                                            } else {
                                                $amount = null;
                                            }
                                            
                                            // Get unit (always from original tariff, never dirty)
                                            $unitValue = $unitField && $tariff ? $tariff->getAttribute($unitField) : null;
                                            
                                            // Determine unit display
                                            if ($category === 'LM') {
                                                $unit = ($unitValue === 'LM') ? 'LM' : '€';
                                            } else {
                                                $unit = '€';
                                            }
                                            
                                            // Format amount for display
                                            $formattedValue = '—';
                                            
                                            if (!$isEditing && $amount !== null && $amount !== '') {
                                                $amountFloat = (float) $amount;
                                                if ($amountFloat == (int) $amountFloat) {
                                                    $formattedValue = number_format($amountFloat, 0) . ' ' . $unit;
                                                } else {
                                                    $formattedValue = number_format($amountFloat, 1, ',', '') . ' ' . $unit;
                                                }
                                            }
                                        @endphp
                                        
                                        <td class="px-3 py-2 text-center text-gray-900 dark:text-gray-100
                                            {{ $isDirty ? 'bg-amber-50 ring-1 ring-amber-300 dark:bg-amber-900/20 dark:ring-amber-700' : '' }}
                                            {{ $isEditing ? 'ring-2 ring-primary-500' : '' }}">
                                            <div class="flex items-center justify-center gap-2">
                                                @if($isEditing && $tariffId)
                                                    {{-- Editing mode: show input --}}
                                                    <input 
                                                        type="text" 
                                                        class="fi-input w-24 text-sm text-center rounded border-gray-300 dark:border-gray-600"
                                                        wire:model.lazy="dirty.{{ $tariffId }}.{{ $field }}"
                                                        wire:blur="stopEditing"
                                                        autofocus
                                                        placeholder="0"
                                                    />
                                                @elseif($tariffId)
                                                    {{-- Editable cell: show button with edit icon --}}
                                                    <div class="flex items-center justify-center gap-1">
                                                        <button 
                                                            type="button" 
                                                            class="flex-1 text-center hover:bg-gray-50 dark:hover:bg-gray-800 rounded px-2 py-1 transition-colors"
                                                            wire:click="startEditing({{ $tariffId }}, '{{ $field }}')">
                                                            {{ $formattedValue }}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="text-xs text-primary-600 hover:text-primary-700 dark:text-primary-400 p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-800"
                                                            wire:click="openTariffEditor({{ $tariffId }})"
                                                            title="Edit tariff details">
                                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                @else
                                                    {{-- No tariff: show "—" with edit/create link --}}
                                                    <span>{{ $formattedValue }}</span>
                                                    @php
                                                        $createUrl = $categoryData['create_url'] ?? null;
                                                    @endphp
                                                    @if($editUrl)
                                                        <a href="{{ $editUrl }}" 
                                                           class="text-xs text-gray-400 hover:text-primary-500 transition-colors"
                                                           title="Edit mapping">
                                                            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                            </svg>
                                                        </a>
                                                    @elseif($createUrl)
                                                        <a href="{{ $createUrl }}" 
                                                           class="text-xs text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300 transition-colors"
                                                           title="Create mapping">
                                                            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                                            </svg>
                                                        </a>
                                                    @endif
                                                @endif
                                            </div>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                            
                            {{-- Total Row --}}
                            <tr class="border-b border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                                <td class="px-3 py-2 font-medium text-gray-700 dark:text-gray-300">
                                    <strong>Total</strong>
                                </td>
                                
                                @foreach($categoryOrder as $category)
                                    @php
                                        $categoryData = $categories[$category] ?? null;
                                        $tariffId = $categoryData['tariff_id'] ?? null;
                                        $tariff = $categoryData['tariff'] ?? null;
                                        
                                        // Calculate total using original + dirty overrides
                                        $total = 0.0;
                                        
                                        // Field to unit field mapping
                                        $fieldToUnitField = [
                                            'base_freight_amount' => 'base_freight_unit',
                                            'baf_amount' => 'baf_unit',
                                            'ets_amount' => 'ets_unit',
                                            'port_additional_amount' => 'port_additional_unit',
                                            'admin_fxe_amount' => 'admin_fxe_unit',
                                            'thc_amount' => 'thc_unit',
                                            'measurement_costs_amount' => 'measurement_costs_unit',
                                            'congestion_surcharge_amount' => 'congestion_surcharge_unit',
                                            'iccm_amount' => 'iccm_unit',
                                        ];
                                        
                                        foreach ($fieldToUnitField as $amountField => $unitField) {
                                            $value = null;
                                            
                                            // Check dirty first, then original
                                            if ($tariffId && isset($this->dirty[$tariffId][$amountField])) {
                                                $dirtyValue = $this->dirty[$tariffId][$amountField];
                                                $value = $this->normalizeNumeric($dirtyValue);
                                            } elseif ($tariff) {
                                                $value = (float) ($tariff->getAttribute($amountField) ?? 0);
                                            }
                                            
                                            // For RORO/LM category, only include fields with unit='LM'
                                            if ($category === 'LM') {
                                                // Get unit value
                                                $unitValue = null;
                                                if ($tariff) {
                                                    $unitValue = $tariff->getAttribute($unitField);
                                                }
                                                
                                                // Only include if unit is 'LM'
                                                if ($unitValue !== 'LM') {
                                                    continue;
                                                }
                                            }
                                            
                                            if ($value !== null && $value >= 0) {
                                                $total += $value;
                                            }
                                        }
                                        
                                        // Format total: for LM category, show in LM; otherwise show in EUR
                                        if ($category === 'LM' && $total > 0) {
                                            $formattedTotal = number_format($total, 1, ',', '') . ' LM';
                                        } else {
                                            $formattedTotal = $total > 0 ? number_format($total, 2, ',', '') . ' €' : '—';
                                        }
                                    @endphp
                                    
                                    <td class="px-3 py-2 text-center font-bold text-gray-900 dark:text-gray-100">
                                        {{ $formattedTotal }}
                                    </td>
                                @endforeach
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                {{-- Sales Prices Section (Collapsible) --}}
                <x-filament::section
                    class="mt-4"
                    :collapsible="true"
                    :collapsed="true"
                    heading="Sales Prices (Mapped Articles)">
                        {{-- Sales Header with Save/Cancel --}}
                        @if($this->hasDirtySalesForPort($portCode))
                            <div class="mb-4 flex items-center justify-end gap-2">
                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ count($this->getDirtyArticleIdsForPort($portCode)) }} changed
                                </span>
                                <x-filament::button 
                                    size="sm"
                                    wire:click="saveSalesPort('{{ $portCode }}')"
                                    wire:loading.attr="disabled">
                                    Save Sales {{ $portCode }}
                                </x-filament::button>
                                <x-filament::button 
                                    size="sm" 
                                    color="gray"
                                    wire:click="cancelSalesPort('{{ $portCode }}')"
                                    wire:loading.attr="disabled">
                                    Cancel
                                </x-filament::button>
                            </div>
                        @endif
                        
                        {{-- Sales Table --}}
                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-gray-700">
                                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Category</th>
                                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300">Article</th>
                                        <th class="px-3 py-2 text-center font-semibold text-gray-700 dark:text-gray-300">Sales Price</th>
                                        <th class="px-3 py-2 text-center font-semibold text-gray-700 dark:text-gray-300">Purchase Total</th>
                                        <th class="px-3 py-2 text-center font-semibold text-gray-700 dark:text-gray-300">Margin</th>
                                        <th class="px-3 py-2 text-center font-semibold text-gray-700 dark:text-gray-300">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($categoryOrder as $category)
                                        @php
                                            $categoryData = $categories[$category] ?? null;
                                            $article = $categoryData['article'] ?? null;
                                            $articleId = $article['id'] ?? null;
                                            $tariff = $categoryData['tariff'] ?? null;
                                            $tariffId = $categoryData['tariff_id'] ?? null;
                                            $editUrl = $categoryData['edit_url'] ?? null;
                                            
                                            // Get purchase total (use dirty overrides if present)
                                            $purchaseTotal = null;
                                            if ($tariff) {
                                                // Calculate with dirty overrides
                                                $total = 0.0;
                                                
                                                // Field to unit field mapping
                                                $fieldToUnitField = [
                                                    'base_freight_amount' => 'base_freight_unit',
                                                    'baf_amount' => 'baf_unit',
                                                    'ets_amount' => 'ets_unit',
                                                    'port_additional_amount' => 'port_additional_unit',
                                                    'admin_fxe_amount' => 'admin_fxe_unit',
                                                    'thc_amount' => 'thc_unit',
                                                    'measurement_costs_amount' => 'measurement_costs_unit',
                                                    'congestion_surcharge_amount' => 'congestion_surcharge_unit',
                                                    'iccm_amount' => 'iccm_unit',
                                                ];
                                                
                                                foreach ($fieldToUnitField as $amountField => $unitField) {
                                                    $value = null;
                                                    
                                                    if ($tariffId && isset($this->dirty[$tariffId][$amountField])) {
                                                        $dirtyValue = $this->dirty[$tariffId][$amountField];
                                                        $value = $this->normalizeNumeric($dirtyValue);
                                                    } elseif ($tariff) {
                                                        $value = (float) ($tariff->getAttribute($amountField) ?? 0);
                                                    }
                                                    
                                                    // For RORO/LM category, only include fields with unit='LM'
                                                    if ($category === 'LM') {
                                                        // Get unit value
                                                        $unitValue = null;
                                                        if ($tariff) {
                                                            $unitValue = $tariff->getAttribute($unitField);
                                                        }
                                                        
                                                        // Only include if unit is 'LM'
                                                        if ($unitValue !== 'LM') {
                                                            continue;
                                                        }
                                                    }
                                                    
                                                    if ($value !== null && $value >= 0) {
                                                        $total += $value;
                                                    }
                                                }
                                                
                                                $purchaseTotal = $total > 0 ? $total : null;
                                            }
                                            
                                            // Get sales price (dirty or original)
                                            $salesPrice = null;
                                            if ($articleId && isset($this->dirtySales[$articleId]['unit_price'])) {
                                                $salesPrice = $this->normalizeNumeric($this->dirtySales[$articleId]['unit_price']);
                                            } elseif ($article) {
                                                $salesPrice = $article['unit_price'] ?? null;
                                            }
                                            
                                            // Calculate margin
                                            $margin = null;
                                            $marginPercent = null;
                                            if ($salesPrice !== null && $purchaseTotal !== null && $purchaseTotal > 0) {
                                                $margin = $salesPrice - $purchaseTotal;
                                                $marginPercent = ($margin / $purchaseTotal) * 100;
                                            }
                                            
                                            $isEditingSales = $articleId && $this->editingKey === "sales:{$articleId}";
                                            $isDirtySales = $articleId && isset($this->dirtySales[$articleId]['unit_price']);
                                            $currency = $article['currency'] ?? 'EUR';
                                        @endphp
                                        
                                        <tr class="border-b border-gray-100 dark:border-gray-800">
                                            <td class="px-3 py-2 font-medium text-gray-700 dark:text-gray-300">
                                                {{ $category }}
                                            </td>
                                            <td class="px-3 py-2 text-gray-900 dark:text-gray-100">
                                                @if($article)
                                                    <div>
                                                        @if($articleId)
                                                            <a 
                                                                href="{{ \App\Filament\Resources\RobawsArticleResource::getUrl('view', ['record' => $articleId]) }}"
                                                                class="font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300 hover:underline"
                                                                target="_blank"
                                                            >
                                                                {{ $article['article_name'] ?? '—' }}
                                                            </a>
                                                        @else
                                                            <div class="font-medium">{{ $article['article_name'] ?? '—' }}</div>
                                                        @endif
                                                        <div class="text-xs text-gray-500">{{ $article['article_code'] ?? '' }}</div>
                                                    </div>
                                                @else
                                                    <span class="text-gray-400">—</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-center 
                                                {{ $isDirtySales ? 'bg-amber-50 ring-1 ring-amber-300 dark:bg-amber-900/20 dark:ring-amber-700' : '' }}
                                                {{ $isEditingSales ? 'ring-2 ring-primary-500' : '' }}">
                                                @if($articleId && $isEditingSales)
                                                    <input 
                                                        type="text" 
                                                        class="fi-input w-24 text-sm text-center rounded border-gray-300 dark:border-gray-600"
                                                        wire:model.lazy="dirtySales.{{ $articleId }}.unit_price"
                                                        wire:blur="stopEditingSales"
                                                        autofocus
                                                        placeholder="0"
                                                    />
                                                @elseif($articleId)
                                                    <button 
                                                        type="button" 
                                                        class="w-full text-center hover:bg-gray-50 dark:hover:bg-gray-800 rounded px-2 py-1 transition-colors"
                                                        wire:click="startEditingSales({{ $articleId }})">
                                                        {{ $salesPrice !== null ? number_format($salesPrice, 2, ',', '') . ' ' . $currency : '—' }}
                                                    </button>
                                                @else
                                                    <span class="text-gray-400">—</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-center text-gray-900 dark:text-gray-100">
                                                @if($purchaseTotal !== null)
                                                    @if($category === 'LM')
                                                        {{ number_format($purchaseTotal, 1, ',', '') . ' LM' }}
                                                    @else
                                                        {{ number_format($purchaseTotal, 2, ',', '') . ' €' }}
                                                    @endif
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-center text-gray-900 dark:text-gray-100">
                                                @if($margin !== null && $marginPercent !== null)
                                                    <div>
                                                        <div class="{{ $margin >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                                            {{ number_format($margin, 2, ',', '') }} €
                                                        </div>
                                                        <div class="text-xs text-gray-500">
                                                            ({{ number_format($marginPercent, 1, ',', '') }}%)
                                                        </div>
                                                    </div>
                                                @else
                                                    <span class="text-gray-400">—</span>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                @php
                                                    $createUrl = $categoryData['create_url'] ?? null;
                                                @endphp
                                                @if($editUrl)
                                                    <a href="{{ $editUrl }}" 
                                                       class="text-xs text-primary-600 hover:text-primary-700 dark:text-primary-400"
                                                       title="Edit mapping">
                                                        Edit Mapping
                                                    </a>
                                                @elseif($createUrl)
                                                    <a href="{{ $createUrl }}" 
                                                       class="text-xs text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300 font-medium"
                                                       title="Create mapping">
                                                        Create Mapping
                                                    </a>
                                                @else
                                                    <span class="text-gray-400">—</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                </x-filament::section>
            </div>
        @endforeach

        @if(empty($ports))
            <div class="rounded-lg border border-gray-200 bg-white p-6 text-center dark:border-gray-700 dark:bg-gray-900">
                <p class="text-gray-500 dark:text-gray-400">No purchase rates found for Grimaldi.</p>
            </div>
        @endif
    </div>

    {{-- Tariff Details Editor Modal --}}
    @if($this->editingTariffId)
        <div 
            x-data="{ show: true }"
            x-show="show"
            x-cloak
            class="fixed inset-0 z-50 overflow-y-auto"
            x-on:keydown.escape.window="$wire.closeTariffEditor()"
            wire:ignore.self>
            {{-- Backdrop --}}
            <div 
                x-show="show"
            x-transition:enter="ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
            x-on:click="$wire.closeTariffEditor()">
        </div>
        
        {{-- Modal --}}
        <div class="flex min-h-full items-center justify-center p-4">
            <div 
                x-show="show"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                class="relative transform overflow-hidden rounded-lg bg-white dark:bg-gray-800 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-4xl">
                {{-- Header --}}
                <div class="bg-white dark:bg-gray-800 px-4 pb-4 pt-5 sm:p-6 sm:pb-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold leading-6 text-gray-900 dark:text-white">
                        Edit Purchase Tariff Details
                    </h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Edit all tariff fields including amounts, units, effective dates, and metadata.
                    </p>
                </div>
                
                {{-- Content --}}
                <div class="bg-white dark:bg-gray-800 px-4 py-5 sm:p-6">
                    @if($this->editingTariffId)
                        @php
                            $tariffId = $this->editingTariffId;
                            $details = $this->tariffDetails[$tariffId] ?? [];
                        @endphp
                        
                        <div class="space-y-6">
                            {{-- Base Freight --}}
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Base Freight Amount
                                    </label>
                                    <input 
                                        type="number"
                                        step="0.01"
                                        wire:model="tariffDetails.{{ $tariffId }}.base_freight_amount"
                                        class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500 disabled:bg-gray-50 disabled:text-gray-500 disabled:dark:bg-gray-900 disabled:dark:text-gray-400"
                                        required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Base Freight Unit
                                    </label>
                                    <select 
                                        wire:model="tariffDetails.{{ $tariffId }}.base_freight_unit"
                                        class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500">
                                        <option value="LUMPSUM">LUMPSUM</option>
                                        <option value="LM">LM</option>
                                    </select>
                                </div>
                            </div>
                            
                            {{-- Surcharges --}}
                            <div class="space-y-4">
                                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Surcharges</h3>
                                @foreach([
                                    ['amount' => 'baf_amount', 'unit' => 'baf_unit', 'label' => 'BAF'],
                                    ['amount' => 'ets_amount', 'unit' => 'ets_unit', 'label' => 'ETS'],
                                    ['amount' => 'port_additional_amount', 'unit' => 'port_additional_unit', 'label' => 'Port Additional'],
                                    ['amount' => 'admin_fxe_amount', 'unit' => 'admin_fxe_unit', 'label' => 'Admin Fee'],
                                    ['amount' => 'thc_amount', 'unit' => 'thc_unit', 'label' => 'THC'],
                                    ['amount' => 'measurement_costs_amount', 'unit' => 'measurement_costs_unit', 'label' => 'Measurement Costs'],
                                    ['amount' => 'congestion_surcharge_amount', 'unit' => 'congestion_surcharge_unit', 'label' => 'Congestion Surcharge'],
                                    ['amount' => 'iccm_amount', 'unit' => 'iccm_unit', 'label' => 'ICCM'],
                                ] as $surcharge)
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                            {{ $surcharge['label'] }} Amount
                                        </label>
                                        <input 
                                            type="number"
                                            step="0.01"
                                            wire:model="tariffDetails.{{ $tariffId }}.{{ $surcharge['amount'] }}"
                                            class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                            {{ $surcharge['label'] }} Unit
                                        </label>
                                        <select 
                                            wire:model="tariffDetails.{{ $tariffId }}.{{ $surcharge['unit'] }}"
                                            class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500">
                                            <option value="LUMPSUM">LUMPSUM</option>
                                            <option value="LM">LM</option>
                                        </select>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                            
                            {{-- Metadata --}}
                            <div class="grid grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Effective From
                                    </label>
                                    <input 
                                        type="date"
                                        wire:model="tariffDetails.{{ $tariffId }}.effective_from"
                                        class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Effective To
                                    </label>
                                    <input 
                                        type="date"
                                        wire:model="tariffDetails.{{ $tariffId }}.effective_to"
                                        class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Currency
                                    </label>
                                    <select 
                                        wire:model="tariffDetails.{{ $tariffId }}.currency"
                                        class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500">
                                        <option value="EUR">EUR</option>
                                        <option value="USD">USD</option>
                                    </select>
                                </div>
                            </div>
                            
                            {{-- Date Fields --}}
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Update Date
                                    </label>
                                    <input 
                                        type="date"
                                        wire:model="tariffDetails.{{ $tariffId }}.update_date"
                                        class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Validity Date
                                    </label>
                                    <input 
                                        type="date"
                                        wire:model="tariffDetails.{{ $tariffId }}.validity_date"
                                        class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500" />
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div class="flex items-center">
                                    <input 
                                        type="checkbox"
                                        wire:model="tariffDetails.{{ $tariffId }}.is_active"
                                        id="tariff-active-{{ $tariffId }}"
                                        class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                                    <label for="tariff-active-{{ $tariffId }}" class="ml-2 block text-sm text-gray-700 dark:text-gray-300">
                                        Active
                                    </label>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Source
                                    </label>
                                    <select 
                                        wire:model="tariffDetails.{{ $tariffId }}.source"
                                        class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500">
                                        <option value="">None</option>
                                        <option value="excel">Excel</option>
                                        <option value="import">Import</option>
                                        <option value="manual">Manual</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Notes
                                </label>
                                <textarea 
                                    wire:model="tariffDetails.{{ $tariffId }}.notes"
                                    rows="3"
                                    class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500"></textarea>
                            </div>
                        </div>
                    @endif
                </div>
                
                {{-- Footer --}}
                <div class="bg-gray-50 dark:bg-gray-700 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6 border-t border-gray-200 dark:border-gray-600">
                    <x-filament::button 
                        wire:click="saveTariffDetails"
                        color="primary"
                        class="ml-3">
                        Save
                    </x-filament::button>
                    <x-filament::button 
                        wire:click="closeTariffEditor"
                        color="gray">
                        Cancel
                    </x-filament::button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
