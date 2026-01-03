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
                                                    {{-- Editable cell: show button --}}
                                                    <button 
                                                        type="button" 
                                                        class="w-full text-center hover:bg-gray-50 dark:hover:bg-gray-800 rounded px-2 py-1 transition-colors"
                                                        wire:click="startEditing({{ $tariffId }}, '{{ $field }}')">
                                                        {{ $formattedValue }}
                                                    </button>
                                                @else
                                                    {{-- No tariff: show "—" with edit link --}}
                                                    <span>{{ $formattedValue }}</span>
                                                    @if($editUrl)
                                                        <a href="{{ $editUrl }}" 
                                                           class="text-xs text-gray-400 hover:text-primary-500 transition-colors"
                                                           title="Edit mapping">
                                                            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
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
                                                        <div class="font-medium">{{ $article['article_name'] ?? '—' }}</div>
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
                                                @if($editUrl)
                                                    <a href="{{ $editUrl }}" 
                                                       class="text-xs text-primary-600 hover:text-primary-700 dark:text-primary-400"
                                                       title="Edit mapping">
                                                        Edit Mapping
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
</x-filament-panels::page>
