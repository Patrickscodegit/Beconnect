<x-filament-widgets::widget>
    <x-filament::section>
        <div class="space-y-4">
            {{-- Header --}}
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold">Article Cache Status</h3>
                <div class="flex gap-2">
                    <x-filament::button
                        tag="a"
                        href="{{ route('filament.admin.resources.robaws-articles.index') }}"
                        size="sm"
                        outlined
                    >
                        View Articles
                    </x-filament::button>
                    <x-filament::button
                        tag="a"
                        href="{{ route('filament.admin.resources.robaws-sync-logs.index') }}"
                        size="sm"
                        outlined
                    >
                        View Sync Logs
                    </x-filament::button>
                </div>
            </div>

            {{-- Article Count Cards --}}
            <div class="grid gap-4 md:grid-cols-4">
                {{-- Total Articles --}}
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-gray-800">
                    <div class="flex items-center gap-3">
                        <div class="rounded-full bg-primary-500/10 p-3">
                            <svg class="h-6 w-6 text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M12 17.25h8.25" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Total Articles</p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($this->getViewData()['articleCount']) }}</p>
                        </div>
                    </div>
                </div>

                {{-- Parent Articles --}}
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-gray-800">
                    <div class="flex items-center gap-3">
                        <div class="rounded-full bg-success-500/10 p-3">
                            <svg class="h-6 w-6 text-success-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Parent Articles</p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($this->getViewData()['parentCount']) }}</p>
                        </div>
                    </div>
                </div>

                {{-- Surcharges --}}
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-gray-800">
                    <div class="flex items-center gap-3">
                        <div class="rounded-full bg-warning-500/10 p-3">
                            <svg class="h-6 w-6 text-warning-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Surcharges</p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($this->getViewData()['surchargeCount']) }}</p>
                        </div>
                    </div>
                </div>

                {{-- Parent-Child Relationships --}}
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-gray-800">
                    <div class="flex items-center gap-3">
                        <div class="rounded-full bg-info-500/10 p-3">
                            <svg class="h-6 w-6 text-info-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Relationships</p>
                            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($this->getViewData()['childrenCount']) }}</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Last Sync Info --}}
            @if($this->getViewData()['lastSyncAt'])
                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div>
                                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Last Sync</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    {{ $this->getViewData()['lastSyncAt']->diffForHumans() }}
                                    ({{ $this->getViewData()['lastSyncAt']->format('M j, Y H:i') }})
                                </p>
                            </div>
                            
                            @if($this->getViewData()['lastSyncStatus'])
                                <x-filament::badge
                                    :color="match($this->getViewData()['lastSyncStatus']) {
                                        'success' => 'success',
                                        'failed' => 'danger',
                                        'pending' => 'warning',
                                        default => 'gray',
                                    }"
                                >
                                    {{ ucfirst($this->getViewData()['lastSyncStatus']) }}
                                </x-filament::badge>
                            @endif
                        </div>

                        <div class="text-right">
                            @if($this->getViewData()['lastSyncCount'])
                                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ number_format($this->getViewData()['lastSyncCount']) }} articles synced
                                </p>
                            @endif
                            @if($this->getViewData()['lastSyncDuration'])
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    Duration: {{ $this->getViewData()['lastSyncDuration'] }}s
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            @else
                <div class="rounded-lg border border-gray-200 p-4 text-center dark:border-gray-700">
                    <p class="text-sm text-gray-500 dark:text-gray-400">No sync history available</p>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

