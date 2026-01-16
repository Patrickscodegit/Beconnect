<x-filament-panels::page>
    <div class="space-y-6" wire:poll.5s>
        
        {{-- Status Card --}}
        <div class="rounded-lg border p-6 {{ $this->getSyncStatus() === 'running' ? 'border-primary-300 bg-primary-50 dark:border-primary-700 dark:bg-primary-950' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900' }}">
            @if($warning = $this->getStaleSyncWarning())
                <div class="mb-4 rounded-md border border-warning-300 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-200">
                    {{ $warning }}
                </div>
            @endif
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
            $batchStats = $this->getBatchStats();
            $queueReadiness = $this->getQueueReadiness();
            $queueDiagnostics = $this->getQueueDiagnostics();
            $lastSyncLog = $this->getLastSyncLog();
        @endphp

        @if(!empty($queueStats['error']))
            <x-filament::card>
                <div class="rounded-md border border-danger-300 bg-danger-50 p-3 text-sm text-danger-800 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-200">
                    Queue stats error: {{ $queueStats['error'] }}
                </div>
            </x-filament::card>
        @endif

        @if(!empty($batchStats['error']))
            <x-filament::card>
                <div class="rounded-md border border-danger-300 bg-danger-50 p-3 text-sm text-danger-800 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-200">
                    Batch stats error: {{ $batchStats['error'] }}
                </div>
            </x-filament::card>
        @endif

        @if(!empty($queueDiagnostics['error']))
            <x-filament::card>
                <div class="rounded-md border border-danger-300 bg-danger-50 p-3 text-sm text-danger-800 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-200">
                    Queue diagnostics error: {{ $queueDiagnostics['error'] }}
                </div>
            </x-filament::card>
        @endif
        
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

        @if(!empty($batchStats['has_batch']))
            <x-filament::card>
                <h3 class="mb-2 text-lg font-semibold text-gray-900 dark:text-white">Full Sync Batch</h3>
                <div class="grid gap-4 md:grid-cols-4">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-gray-900 dark:text-white">
                            {{ $batchStats['total_jobs'] }}
                        </div>
                        <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            Total Jobs
                        </div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-gray-900 dark:text-white">
                            {{ $batchStats['processed_jobs'] }}
                        </div>
                        <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            Processed
                        </div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-gray-900 dark:text-white">
                            {{ $batchStats['pending_jobs'] }}
                        </div>
                        <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            Pending
                        </div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold {{ $batchStats['failed_jobs'] > 0 ? 'text-red-600' : 'text-green-600' }}">
                            {{ $batchStats['failed_jobs'] }}
                        </div>
                        <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            Failed
                        </div>
                    </div>
                </div>
            </x-filament::card>
        @endif

        <div class="grid gap-4 md:grid-cols-2">
            <x-filament::card>
                <h3 class="mb-2 text-lg font-semibold text-gray-900 dark:text-white">Queue Readiness</h3>
                <div class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
                    <div>Connection: <span class="font-medium">{{ $queueReadiness['connection'] }}</span></div>
                    <div>Jobs table: <span class="font-medium">{{ $queueReadiness['jobs_table'] ? 'OK' : 'Missing' }}</span></div>
                    <div>Batch table: <span class="font-medium">{{ $queueReadiness['batches_table'] ? 'OK' : 'Missing' }}</span></div>
                    <div>Failed jobs table: <span class="font-medium">{{ $queueReadiness['failed_table'] ? 'OK' : 'Missing' }}</span></div>
                </div>
                @if($queueReadiness['is_sync'])
                    <div class="mt-3 rounded-md border border-warning-300 bg-warning-50 p-2 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-200">
                        Queue connection is set to sync. Background jobs will run inline and may still time out.
                    </div>
                @endif
            </x-filament::card>

            <x-filament::card>
                <h3 class="mb-2 text-lg font-semibold text-gray-900 dark:text-white">Queue Diagnostics</h3>
                @if($queueDiagnostics['has_jobs_table'])
                    <div class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
                        <div>Queue size: <span class="font-medium">{{ $queueDiagnostics['queue_size'] }}</span></div>
                        <div>Oldest job: <span class="font-medium">{{ $queueDiagnostics['oldest_job_at'] ?? 'N/A' }}</span></div>
                        <div>Latest job: <span class="font-medium">{{ $queueDiagnostics['latest_job_at'] ?? 'N/A' }}</span></div>
                    </div>
                @else
                    <div class="text-sm text-gray-600 dark:text-gray-400">Jobs table not found.</div>
                @endif
            </x-filament::card>
        </div>

        <x-filament::card>
            <h3 class="mb-2 text-lg font-semibold text-gray-900 dark:text-white">Last Full Sync</h3>
            @if($lastSyncLog)
                <div class="grid gap-4 md:grid-cols-4 text-sm text-gray-700 dark:text-gray-300">
                    <div>Started: <span class="font-medium">{{ $lastSyncLog->started_at }}</span></div>
                    <div>Completed: <span class="font-medium">{{ $lastSyncLog->completed_at ?? 'Running' }}</span></div>
                    <div>Items synced: <span class="font-medium">{{ $lastSyncLog->items_synced ?? 0 }}</span></div>
                    <div>Status: <span class="font-medium">{{ $lastSyncLog->error_message ? 'Failed' : ($lastSyncLog->completed_at ? 'Completed' : 'Running') }}</span></div>
                </div>
                @if($lastSyncLog->error_message)
                    <div class="mt-3 rounded-md border border-danger-300 bg-danger-50 p-2 text-sm text-danger-800 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-200">
                        {{ $lastSyncLog->error_message }}
                    </div>
                @endif
            @else
                <div class="text-sm text-gray-600 dark:text-gray-400">No full sync logs recorded yet.</div>
            @endif
        </x-filament::card>
        
        {{-- Field Population Stats --}}
        @php
            $fieldStats = $this->getFieldStats();
        @endphp

        @if(!empty($fieldStats['error']))
            <x-filament::card>
                <div class="rounded-md border border-danger-300 bg-danger-50 p-3 text-sm text-danger-800 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-200">
                    Field stats error: {{ $fieldStats['error'] }}
                </div>
            </x-filament::card>
        @endif
        
        <x-filament::card>
            <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Field Population Status</h3>
            
            <div class="space-y-3">
                <div>
                    <div class="mb-1 flex items-center justify-between text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-300">Parent Items</span>
                        <span class="font-medium text-gray-900 dark:text-white">
                            {{ $fieldStats['parent_items'] }} / {{ $fieldStats['total'] }} 
                            ({{ $fieldStats['total'] > 0 ? round(($fieldStats['parent_items']/$fieldStats['total'])*100, 1) : 0 }}%)
                        </span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-full bg-success-600" style="width: {{ $fieldStats['total'] > 0 ? round(($fieldStats['parent_items']/$fieldStats['total'])*100) : 0 }}%"></div>
                    </div>
                </div>
                
                <div>
                    <div class="mb-1 flex items-center justify-between text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-300">Commodity Type</span>
                        <span class="font-medium text-gray-900 dark:text-white">
                            {{ $fieldStats['with_commodity'] }} / {{ $fieldStats['total'] }} 
                            ({{ $fieldStats['total'] > 0 ? round(($fieldStats['with_commodity']/$fieldStats['total'])*100, 1) : 0 }}%)
                        </span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-full bg-warning-600" style="width: {{ $fieldStats['total'] > 0 ? round(($fieldStats['with_commodity']/$fieldStats['total'])*100) : 0 }}%"></div>
                    </div>
                </div>
                
                <div>
                    <div class="mb-1 flex items-center justify-between text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-300">POD Code</span>
                        <span class="font-medium text-gray-900 dark:text-white">
                            {{ $fieldStats['with_pod_code'] }} / {{ $fieldStats['total'] }} 
                            ({{ $fieldStats['total'] > 0 ? round(($fieldStats['with_pod_code']/$fieldStats['total'])*100, 1) : 0 }}%)
                        </span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-full bg-info-600" style="width: {{ $fieldStats['total'] > 0 ? round(($fieldStats['with_pod_code']/$fieldStats['total'])*100) : 0 }}%"></div>
                    </div>
                </div>
                
                <div>
                    <div class="mb-1 flex items-center justify-between text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-300">POL Terminal</span>
                        <span class="font-medium text-gray-900 dark:text-white">
                            {{ $fieldStats['with_pol_terminal'] }} / {{ $fieldStats['total'] }} 
                            ({{ $fieldStats['total'] > 0 ? round(($fieldStats['with_pol_terminal']/$fieldStats['total'])*100, 1) : 0 }}%)
                        </span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-full bg-primary-600" style="width: {{ $fieldStats['total'] > 0 ? round(($fieldStats['with_pol_terminal']/$fieldStats['total'])*100) : 0 }}%"></div>
                    </div>
                </div>
                
                <div>
                    <div class="mb-1 flex items-center justify-between text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-300">Shipping Line</span>
                        <span class="font-medium text-gray-900 dark:text-white">
                            {{ $fieldStats['with_shipping_line'] }} / {{ $fieldStats['total'] }} 
                            ({{ $fieldStats['total'] > 0 ? round(($fieldStats['with_shipping_line']/$fieldStats['total'])*100, 1) : 0 }}%)
                        </span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-full bg-success-600" style="width: {{ $fieldStats['total'] > 0 ? round(($fieldStats['with_shipping_line']/$fieldStats['total'])*100) : 0 }}%"></div>
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

