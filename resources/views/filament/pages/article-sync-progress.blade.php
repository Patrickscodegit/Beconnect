<x-filament-panels::page>
    <div class="space-y-6" wire:poll.5s>
        
        {{-- Status Card --}}
        <div class="rounded-lg border p-6 {{ $this->getSyncStatus() === 'running' ? 'border-primary-300 bg-primary-50 dark:border-primary-700 dark:bg-primary-950' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900' }}">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white">
                        @if($this->getSyncStatus() === 'running')
                            🔄 Syncing
                        @elseif($this->getSyncStatus() === 'complete')
                            ✅ Sync Complete
                        @else
                            ⏸️ No Sync Running
                        @endif
                    </h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        @if($this->getSyncStatus() === 'running')
                            Syncing article metadata from Robaws API...
                            @if($this->getEstimatedTimeRemaining())
                                <br><strong>Estimated time remaining:</strong> {{ $this->getEstimatedTimeRemaining() }}
                            @endif
                        @elseif($this->getSyncStatus() === 'complete')
                            All articles have been synced successfully. Field population is complete.
                        @else
                            No sync has been run yet. Click "Sync Extra Fields" in Articles page to start sync.
                        @endif
                    </p>
                </div>
                
                @if($this->getSyncStatus() === 'running')
                    <div class="h-16 w-16 animate-spin rounded-full border-4 border-primary-200 border-t-primary-600"></div>
                @endif
            </div>
            
            @if($this->getSyncStatus() === 'running')
                <div class="mt-4">
                    <div class="mb-2 flex items-center justify-between text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-300">Progress</span>
                        <span class="font-medium text-primary-600 dark:text-primary-400">{{ $this->getProgressPercentage() }}%</span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-full bg-primary-600 transition-all duration-500" style="width: {{ $this->getProgressPercentage() }}%"></div>
                    </div>
                </div>
            @endif
        </div>
        
        {{-- Queue Stats --}}
        @php
            $queueStats = $this->getQueueStats();
        @endphp
        
        <div class="grid gap-4 md:grid-cols-3">
            <x-filament::card>
                <div class="text-center">
                    <div class="text-3xl font-bold text-gray-900 dark:text-white">
                        {{ $queueStats['pending_jobs'] }}
                    </div>
                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        Pending Jobs
                    </div>
                </div>
            </x-filament::card>
            
            <x-filament::card>
                <div class="text-center">
                    <div class="text-3xl font-bold text-gray-900 dark:text-white">
                        {{ $queueStats['article_jobs'] }}
                    </div>
                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        Article Metadata Jobs
                    </div>
                </div>
            </x-filament::card>
            
            <x-filament::card>
                <div class="text-center">
                    <div class="text-3xl font-bold {{ $queueStats['failed_jobs'] > 0 ? 'text-red-600' : 'text-green-600' }}">
                        {{ $queueStats['failed_jobs'] }}
                    </div>
                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        Failed Jobs
                    </div>
                </div>
            </x-filament::card>
        </div>
        
        {{-- Field Population Stats --}}
        @php
            $fieldStats = $this->getFieldStats();
        @endphp
        
        <x-filament::card>
            <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Field Population Status</h3>
            
            <div class="space-y-3">
                <div>
                    <div class="mb-1 flex items-center justify-between text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-300">Parent Items</span>
                        <span class="font-medium text-gray-900 dark:text-white">
                            {{ $fieldStats['parent_items'] }} / {{ $fieldStats['total'] }} 
                            ({{ round(($fieldStats['parent_items']/$fieldStats['total'])*100, 1) }}%)
                        </span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-full bg-success-600" style="width: {{ round(($fieldStats['parent_items']/$fieldStats['total'])*100) }}%"></div>
                    </div>
                </div>
                
                <div>
                    <div class="mb-1 flex items-center justify-between text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-300">Commodity Type</span>
                        <span class="font-medium text-gray-900 dark:text-white">
                            {{ $fieldStats['with_commodity'] }} / {{ $fieldStats['total'] }} 
                            ({{ round(($fieldStats['with_commodity']/$fieldStats['total'])*100, 1) }}%)
                        </span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-full bg-warning-600" style="width: {{ round(($fieldStats['with_commodity']/$fieldStats['total'])*100) }}%"></div>
                    </div>
                </div>
                
                <div>
                    <div class="mb-1 flex items-center justify-between text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-300">POD Code</span>
                        <span class="font-medium text-gray-900 dark:text-white">
                            {{ $fieldStats['with_pod_code'] }} / {{ $fieldStats['total'] }} 
                            ({{ round(($fieldStats['with_pod_code']/$fieldStats['total'])*100, 1) }}%)
                        </span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-full bg-info-600" style="width: {{ round(($fieldStats['with_pod_code']/$fieldStats['total'])*100) }}%"></div>
                    </div>
                </div>
                
                <div>
                    <div class="mb-1 flex items-center justify-between text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-300">POL Terminal</span>
                        <span class="font-medium text-gray-900 dark:text-white">
                            {{ $fieldStats['with_pol_terminal'] }} / {{ $fieldStats['total'] }} 
                            ({{ round(($fieldStats['with_pol_terminal']/$fieldStats['total'])*100, 1) }}%)
                        </span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-full bg-primary-600" style="width: {{ round(($fieldStats['with_pol_terminal']/$fieldStats['total'])*100) }}%"></div>
                    </div>
                </div>
                
                <div>
                    <div class="mb-1 flex items-center justify-between text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-300">Shipping Line</span>
                        <span class="font-medium text-gray-900 dark:text-white">
                            {{ $fieldStats['with_shipping_line'] }} / {{ $fieldStats['total'] }} 
                            ({{ round(($fieldStats['with_shipping_line']/$fieldStats['total'])*100, 1) }}%)
                        </span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-full bg-success-600" style="width: {{ round(($fieldStats['with_shipping_line']/$fieldStats['total'])*100) }}%"></div>
                    </div>
                </div>
            </div>
        </x-filament::card>
        
        {{-- Recently Updated Articles --}}
        <x-filament::card>
            <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Recently Updated Articles</h3>
            
            <div class="space-y-2">
                @foreach($this->getRecentArticles() as $article)
                    <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <div class="flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ Str::limit($article['name'], 60) }}
                            </p>
                            <div class="mt-1 flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                                <span>Updated: {{ $article['updated_at']->diffForHumans() }}</span>
                                @if($article['is_parent'])
                                    <span class="inline-flex items-center rounded bg-success-100 px-2 py-0.5 text-xs font-medium text-success-800 dark:bg-success-900 dark:text-success-200">
                                        Parent Item
                                    </span>
                                @endif
                                @if($article['commodity_type'])
                                    <span class="inline-flex items-center rounded bg-warning-100 px-2 py-0.5 text-xs font-medium text-warning-800 dark:bg-warning-900 dark:text-warning-200">
                                        {{ $article['commodity_type'] }}
                                    </span>
                                @endif
                                @if($article['pod_code'])
                                    <span class="inline-flex items-center rounded bg-info-100 px-2 py-0.5 text-xs font-medium text-info-800 dark:bg-info-900 dark:text-info-200">
                                        POD: {{ $article['pod_code'] }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::card>
        
        {{-- Optimization Metrics --}}
        @php
            $optimizationStats = $this->getOptimizationStats();
        @endphp
        
        <x-filament::card>
            <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">⚡ Optimization Metrics</h3>
            
            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-lg border border-success-200 bg-success-50 p-4 dark:border-success-700 dark:bg-success-950">
                    <div class="text-2xl font-bold text-success-600 dark:text-success-400">
                        {{ number_format($optimizationStats['api_calls_saved_from_webhooks']) }}
                    </div>
                    <div class="mt-1 text-sm text-success-700 dark:text-success-300">
                        API Calls Saved (24h)
                    </div>
                    <div class="mt-1 text-xs text-success-600 dark:text-success-400">
                        From webhook optimization (2 → 0 API calls per webhook)
                    </div>
                </div>
                
                <div class="rounded-lg border border-success-200 bg-success-50 p-4 dark:border-success-700 dark:bg-success-950">
                    <div class="text-2xl font-bold text-success-600 dark:text-success-400">
                        {{ $optimizationStats['avg_webhook_processing_time_ms'] > 0 ? $optimizationStats['avg_webhook_processing_time_ms'] . 'ms' : 'N/A' }}
                    </div>
                    <div class="mt-1 text-sm text-success-700 dark:text-success-300">
                        Avg Webhook Processing Time
                    </div>
                    <div class="mt-1 text-xs text-success-600 dark:text-success-400">
                        @if($optimizationStats['avg_webhook_processing_time_ms'] > 0)
                            @if($optimizationStats['avg_webhook_processing_time_ms'] < 100)
                                ⚡ Optimized (<100ms target)
                            @else
                                {{ round($optimizationStats['avg_webhook_processing_time_ms'] / 1000, 2) }}s per webhook
                            @endif
                        @else
                            No processing time data
                        @endif
                    </div>
                </div>
                
                <div class="rounded-lg border border-info-200 bg-info-50 p-4 dark:border-info-700 dark:bg-info-950">
                    <div class="text-2xl font-bold text-info-600 dark:text-info-400">
                        ~{{ number_format($optimizationStats['api_calls_saved_per_sync']) }}
                    </div>
                    <div class="mt-1 text-sm text-info-700 dark:text-info-300">
                        API Calls Saved Per Sync
                    </div>
                    <div class="mt-1 text-xs text-info-600 dark:text-info-400">
                        {{ $optimizationStats['optimization_percentage'] }}% reduction (articles with extraFields)
                    </div>
                </div>
                
                <div class="rounded-lg border border-info-200 bg-info-50 p-4 dark:border-info-700 dark:bg-info-950">
                    <div class="text-2xl font-bold text-info-600 dark:text-info-400">
                        {{ number_format($optimizationStats['articles_with_extraFields']) }}
                    </div>
                    <div class="mt-1 text-sm text-info-700 dark:text-info-300">
                        Articles Optimized
                    </div>
                    <div class="mt-1 text-xs text-info-600 dark:text-info-400">
                        Don't need API calls (have extraFields)
                    </div>
                </div>
            </div>
        </x-filament::card>
        
        {{-- Actions --}}
        <x-filament::card>
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Quick Actions</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        Manually refresh to see latest status
                    </p>
                </div>
                <x-filament::button wire:click="$refresh">
                    Refresh Now
                </x-filament::button>
            </div>
        </x-filament::card>
        
    </div>
</x-filament-panels::page>

