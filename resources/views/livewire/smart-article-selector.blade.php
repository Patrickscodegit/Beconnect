<div class="smart-article-selector">
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Smart Article Suggestions</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Based on your POL, POD, schedule, and commodity types
            </p>
        </div>
        
        {{-- Controls --}}
        <div class="flex items-center gap-4">
            <div class="flex items-center gap-2">
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Min Match:</label>
                <select 
                    wire:model="minMatchPercentage" 
                    wire:change="updateMinMatchPercentage($event.target.value)"
                    class="rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                >
                    <option value="20">20%</option>
                    <option value="30">30%</option>
                    <option value="50">50%</option>
                    <option value="70">70%</option>
                </select>
            </div>
            
            <div class="flex items-center gap-2">
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Max Articles:</label>
                <select 
                    wire:model="maxArticles" 
                    wire:change="updateMaxArticles($event.target.value)"
                    class="rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                >
                    <option value="5">5</option>
                    <option value="10">10</option>
                    <option value="15">15</option>
                    <option value="20">20</option>
                </select>
            </div>
            
            <button 
                wire:click="loadSuggestions" 
                wire:loading.attr="disabled"
                class="inline-flex items-center rounded-md bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 disabled:opacity-50"
            >
                <svg wire:loading.remove wire:target="loadSuggestions" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                <svg wire:loading wire:target="loadSuggestions" class="h-4 w-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                Refresh
            </button>
        </div>
    </div>
    
    {{-- Loading State --}}
    <div wire:loading wire:target="loadSuggestions" class="py-8 text-center">
        <div class="inline-block h-8 w-8 animate-spin rounded-full border-2 border-primary-600 border-t-transparent"></div>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Loading smart suggestions...</p>
    </div>
    
    {{-- Suggestions --}}
    <div wire:loading.remove wire:target="loadSuggestions" class="space-y-4">
        @if($suggestedArticles->count() > 0)
            <div class="grid gap-4">
                @foreach($suggestedArticles as $suggestion)
                    @php
                        $article = $suggestion['article'];
                        $isSelected = in_array($article->id, $selectedArticles);
                        $matchPercentage = $suggestion['match_percentage'];
                        $confidence = $suggestion['confidence'];
                        $matchReasons = $suggestion['match_reasons'];
                    @endphp
                    
                    <div class="rounded-lg border p-4 transition-all duration-200 {{ $isSelected ? 'border-primary-300 bg-primary-50 dark:border-primary-700 dark:bg-primary-950' : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:hover:border-gray-600 dark:hover:bg-gray-800' }}">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                {{-- Article Header --}}
                                <div class="flex items-center gap-3 mb-2">
                                    <h4 class="text-base font-semibold text-gray-900 dark:text-white">
                                        {{ $article->description }}
                                    </h4>
                                    
                                    {{-- Match Badge --}}
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $matchPercentage >= 70 ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : ($matchPercentage >= 50 ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' : 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200') }}">
                                        {{ $matchPercentage }}% match
                                    </span>
                                    
                                    {{-- Confidence Badge --}}
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $this->getConfidenceColor($confidence) }} bg-opacity-20">
                                        {{ $this->getConfidenceLabel($confidence) }}
                                    </span>
                                </div>
                                
                                {{-- Article Details --}}
                                <div class="mb-3 space-y-1">
                                    @if($article->article_code)
                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                            <span class="font-medium">Code:</span> {{ $article->article_code }}
                                        </p>
                                    @endif
                                    
                                    @php
                                        // Always show pricing - default to Tier C if no tier assigned
                                        $displayPrice = $this->getTierPrice($article);
                                        $tier = \App\Models\PricingTier::find($this->pricingTierId);
                                        $tierLabel = $tier ? "Tier {$tier->code}" : 'Standard';
                                    @endphp
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        <span class="font-medium">Price:</span> 
                                        €{{ number_format($displayPrice, 2) }} {{ $article->currency }} / {{ $article->unit_type }}
                                        <span class="text-xs text-gray-500">({{ $tierLabel }} pricing)</span>
                                    </p>
                                    
                                    @if($article->shipping_line)
                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                            <span class="font-medium">Shipping Line:</span> {{ $article->shipping_line }}
                                        </p>
                                    @endif
                                    
                                    @if($article->validity_date)
                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                            <span class="font-medium">Validity Date:</span> {{ $article->validity_date->format('d-m-Y') }}
                                        </p>
                                    @endif
                                </div>
                                
                                {{-- Match Reasons --}}
                                @if(!empty($matchReasons))
                                    <div class="mb-3">
                                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Why this matches:</p>
                                        <div class="flex flex-wrap gap-1">
                                            @foreach($matchReasons as $reason)
                                                <span class="inline-flex items-center rounded bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900 dark:text-green-200">
                                                    ✓ {{ $reason }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                                
                                {{-- Additional Info --}}
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    @if($article->pol && $article->pod)
                                        <p><span class="font-medium">Route:</span> {{ $article->pol }} → {{ $article->pod }}</p>
                                    @endif
                                    
                                    @if($article->commodity_type)
                                        <p><span class="font-medium">Commodity:</span> {{ $article->commodity_type }}</p>
                                    @endif
                                </div>
                            </div>
                            
                            {{-- Action Button --}}
                            <div class="ml-4">
                                @if($isEditable)
                                    @if($isSelected)
                                        @php
                                            $isMandatory = $this->isMandatoryChild($article->id);
                                        @endphp
                                        @if($isMandatory)
                                            <span 
                                                class="inline-flex items-center rounded-md bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-600 cursor-not-allowed dark:bg-gray-700 dark:text-gray-400" 
                                                title="This item is mandatory and cannot be removed"
                                            >
                                                <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                                </svg>
                                                Required
                                            </span>
                                        @else
                                            <button 
                                                wire:click="removeArticle({{ $article->id }})"
                                                class="inline-flex items-center rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600"
                                            >
                                                <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                </svg>
                                                Remove
                                            </button>
                                        @endif
                                    @else
                                        <button 
                                            wire:click="selectArticle({{ $article->id }})"
                                            class="inline-flex items-center rounded-md bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600"
                                        >
                                            <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                            </svg>
                                            Add Article
                                        </button>
                                    @endif
                                @else
                                    @if($isSelected)
                                        <span class="inline-flex items-center rounded-md bg-green-100 px-3 py-2 text-sm font-semibold text-green-800">
                                            <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                            Selected
                                        </span>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            {{-- No Suggestions --}}
            <div class="rounded-lg border border-yellow-200 bg-yellow-50 p-8 text-center">
                <i class="fas fa-exclamation-circle text-yellow-600 text-5xl mb-4"></i>
                <h3 class="text-lg font-semibold text-yellow-900 mb-2">No Matching Articles Found</h3>
                <p class="text-sm text-yellow-800 mb-4">
                    We couldn't find pre-configured services matching your exact requirements with {{ $minMatchPercentage }}% confidence.
                </p>
                <div class="bg-white border border-yellow-300 rounded-lg p-5 mb-4 max-w-md mx-auto">
                    <p class="text-sm font-medium text-gray-900 mb-2">
                        <i class="fas fa-user-tie text-blue-600 text-lg mr-2"></i>
                        Don't worry - Our Belgaco team will help!
                    </p>
                    <p class="text-sm text-gray-700">
                        Submit your quotation and our team will review it personally to provide accurate pricing within 24 hours.
                    </p>
                </div>
                <button 
                    wire:click="updateMinMatchPercentage(20)" 
                    class="inline-flex items-center rounded-md bg-yellow-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-yellow-500"
                >
                    <i class="fas fa-adjust mr-2"></i>
                    Try Lowering Match Threshold to 20%
                </button>
            </div>
        @endif
    </div>
    
    {{-- Selected Articles Summary --}}
    @if(count($selectedArticles) > 0)
        <div class="mt-6 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-700 dark:bg-green-950">
            <div class="flex items-center">
                <svg class="h-5 w-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <p class="ml-2 text-sm font-medium text-green-800 dark:text-green-200">
                    {{ count($selectedArticles) }} article(s) selected and added to your quotation
                </p>
            </div>
        </div>
    @endif
</div>
