<div class="p-6 space-y-4">
    <div class="grid grid-cols-2 gap-4">
        <div class="bg-gray-100 dark:bg-gray-800 p-4 rounded-lg">
            <div class="text-sm text-gray-500 dark:text-gray-400">Total Webhooks</div>
            <div class="text-2xl font-bold">{{ number_format($total) }}</div>
        </div>
        
        <div class="bg-gray-100 dark:bg-gray-800 p-4 rounded-lg">
            <div class="text-sm text-gray-500 dark:text-gray-400">Last 24 Hours</div>
            <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ number_format($last24h) }}</div>
        </div>
        
        <div class="bg-gray-100 dark:bg-gray-800 p-4 rounded-lg">
            <div class="text-sm text-gray-500 dark:text-gray-400">Last 7 Days</div>
            <div class="text-2xl font-bold text-indigo-600 dark:text-indigo-400">{{ number_format($last7d) }}</div>
        </div>
        
        <div class="bg-gray-100 dark:bg-gray-800 p-4 rounded-lg">
            <div class="text-sm text-gray-500 dark:text-gray-400">Success Rate</div>
            <div class="text-2xl font-bold text-green-600 dark:text-green-400">
                {{ $total > 0 ? round(($processed / $total) * 100, 1) : 0 }}%
            </div>
        </div>
        
        <div class="bg-gray-100 dark:bg-gray-800 p-4 rounded-lg">
            <div class="text-sm text-gray-500 dark:text-gray-400">Total Failed</div>
            <div class="text-2xl font-bold text-red-600 dark:text-red-400">{{ number_format($failed) }}</div>
        </div>
        
        <div class="bg-gray-100 dark:bg-gray-800 p-4 rounded-lg">
            <div class="text-sm text-gray-500 dark:text-gray-400">Failed (24h)</div>
            <div class="text-2xl font-bold {{ $failedLast24h > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                {{ number_format($failedLast24h) }}
            </div>
        </div>
    </div>
    
    @if($failedLast24h > 0)
        <div class="mt-4 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
            <div class="flex items-center text-red-800 dark:text-red-200">
                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                </svg>
                <span class="font-semibold">Warning:</span>
                <span class="ml-1">{{ $failedLast24h }} webhook(s) failed in the last 24 hours</span>
            </div>
        </div>
    @endif
</div>

