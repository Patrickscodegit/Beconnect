<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        x-data="{
            articles: @entangle($getStatePath()),
            availableArticles: [],
            smartSuggestions: @json($getSmartSuggestions()),
            searchQuery: '',
            loading: true,
            selectedArticleIds: [],
            showSmartSuggestions: true,
            
            init() {
                this.loadArticles();
                this.articles = this.articles || [];
                this.updateSelectedIds();
            },
            
            async loadArticles() {
                this.loading = true;
                try {
                    const carrierCode = '{{ $getCarrierCode() }}';
                    const serviceType = '{{ $getServiceType() }}';
                    const url = '/admin/api/quotation/articles?service_type=' + serviceType + 
                                (carrierCode ? '&carrier_code=' + carrierCode : '');
                    
                    console.log('🔍 Loading articles with URL:', url);
                    console.log('📦 Service Type:', serviceType);
                    console.log('🚢 Carrier Code:', carrierCode);
                    
                    const response = await fetch(url);
                    
                    console.log('📡 Response status:', response.status);
                    console.log('📡 Response ok:', response.ok);
                    
                    if (response.ok) {
                        const data = await response.json();
                        console.log('📊 Received data:', data);
                        console.log('📊 Data.data length:', data.data ? data.data.length : 'no data.data');
                        this.availableArticles = data.data || data || [];
                        console.log('✅ Available articles set to:', this.availableArticles.length, 'items');
                    } else {
                        console.error('❌ Response not ok:', response.status, response.statusText);
                        const errorText = await response.text();
                        console.error('❌ Error body:', errorText);
                    }
                } catch (error) {
                    console.error('❌ Failed to load articles:', error);
                    this.availableArticles = [];
                }
                this.loading = false;
            },
            
            get filteredArticles() {
                if (!this.searchQuery) return this.availableArticles;
                const query = this.searchQuery.toLowerCase();
                return this.availableArticles.filter(article => 
                    article.description.toLowerCase().includes(query) ||
                    (article.article_code && article.article_code.toLowerCase().includes(query))
                );
            },
            
            addArticle(article) {
                // Check if already added
                if (this.selectedArticleIds.includes(article.id)) {
                    return;
                }
                
                // Add the main article
                this.articles.push({
                    id: article.id,
                    robaws_id: article.robaws_article_id,
                    description: article.description,
                    article_code: article.article_code,
                    unit_price: article.unit_price,
                    unit_type: article.unit_type,
                    quantity: 1,
                    is_parent: article.is_parent_article || false,
                    is_child: false,
                    parent_id: null,
                });
                
                // If it's a parent article, automatically add children
                if (article.is_parent_article && article.children && article.children.length > 0) {
                    article.children.forEach(child => {
                        this.articles.push({
                            id: child.id,
                            robaws_id: child.robaws_article_id,
                            description: child.description,
                            article_code: child.article_code,
                            unit_price: child.unit_price,
                            unit_type: child.unit_type,
                            quantity: 1,
                            is_parent: false,
                            is_child: true,
                            parent_id: article.id,
                        });
                    });
                }
                
                this.updateSelectedIds();
            },
            
            removeArticle(index) {
                const article = this.articles[index];
                
                // If removing a parent, also remove its children
                if (article.is_parent) {
                    this.articles = this.articles.filter(a => a.parent_id !== article.id && a !== article);
                } else {
                    this.articles.splice(index, 1);
                }
                
                this.updateSelectedIds();
            },
            
            updateSelectedIds() {
                this.selectedArticleIds = this.articles.map(a => a.id);
            },
            
            updateQuantity(index, quantity) {
                this.articles[index].quantity = Math.max(1, parseInt(quantity) || 1);
            },
            
            updatePrice(index, price) {
                this.articles[index].unit_price = parseFloat(price) || 0;
            },
            
            calculateSubtotal() {
                return this.articles.reduce((sum, article) => {
                    return sum + (article.unit_price * article.quantity);
                }, 0);
            },
            
            formatCurrency(amount) {
                return new Intl.NumberFormat('en-US', {
                    style: 'currency',
                    currency: 'EUR'
                }).format(amount);
            }
        }"
        class="space-y-4"
    >
        {{-- Smart Suggestions --}}
        <div x-show="smartSuggestions.length > 0 && showSmartSuggestions" class="rounded-lg border border-primary-300 bg-primary-50 p-4 dark:border-primary-700 dark:bg-primary-950">
            <div class="mb-3 flex items-center justify-between">
                <h4 class="text-sm font-semibold text-primary-700 dark:text-primary-300 flex items-center gap-2">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                    </svg>
                    Smart Suggestions
                </h4>
                <button @click="showSmartSuggestions = false" class="text-primary-500 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-200">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <p class="mb-3 text-xs text-primary-600 dark:text-primary-400">
                Based on your POL, POD, schedule, and commodity types
            </p>
            
            <div class="space-y-2">
                <template x-for="suggestion in smartSuggestions" :key="'smart-' + suggestion.id">
                    <div
                        class="flex items-center justify-between rounded-lg border border-primary-200 bg-white p-3 transition hover:bg-primary-100 dark:border-primary-600 dark:bg-gray-800 dark:hover:bg-primary-900"
                        :class="selectedArticleIds.includes(suggestion.id) ? 'border-primary-400 bg-primary-100 dark:border-primary-500 dark:bg-primary-900' : ''"
                    >
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-medium text-gray-900 dark:text-white" x-text="suggestion.description"></p>
                                <span class="inline-flex items-center rounded-full bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-800 dark:bg-primary-900 dark:text-primary-200" x-text="suggestion.match_percentage + '% match'"></span>
                            </div>
                            <div class="mt-1 flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                                <span x-show="suggestion.article_code" x-text="'Code: ' + suggestion.article_code"></span>
                                <span x-text="formatCurrency(suggestion.unit_price) + ' / ' + suggestion.unit_type"></span>
                                <span x-show="suggestion.match_reasons && suggestion.match_reasons.length > 0" class="text-primary-600 dark:text-primary-400" x-text="'✓ ' + suggestion.match_reasons.join(', ')""></span>
                            </div>
                        </div>
                        <x-filament::button
                            type="button"
                            size="xs"
                            color="primary"
                            @click="addArticle(suggestion)"
                            x-bind:disabled="selectedArticleIds.includes(suggestion.id)"
                        >
                            <span x-show="!selectedArticleIds.includes(suggestion.id)">Add</span>
                            <span x-show="selectedArticleIds.includes(suggestion.id)">Added</span>
                        </x-filament::button>
                    </div>
                </template>
            </div>
        </div>

        {{-- Article Search and Add --}}
        <div class="rounded-lg border border-gray-300 bg-white p-4 dark:border-gray-600 dark:bg-gray-900">
            <div class="mb-3 flex items-center justify-between">
                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300">All Articles</h4>
                <button x-show="!showSmartSuggestions && smartSuggestions.length > 0" @click="showSmartSuggestions = true" class="text-sm text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-200">
                    Show Smart Suggestions
                </button>
            </div>
            
            {{-- Search Input --}}
            <div class="mb-3">
                <x-filament::input.wrapper>
                    <x-filament::input
                        type="text"
                        x-model="searchQuery"
                        placeholder="Search articles by name or code..."
                    />
                </x-filament::input.wrapper>
            </div>
            
            {{-- Loading State --}}
            <div x-show="loading" class="py-8 text-center">
                <div class="inline-block h-6 w-6 animate-spin rounded-full border-2 border-primary-600 border-t-transparent"></div>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Loading articles...</p>
            </div>
            
            {{-- Article List --}}
            <div x-show="!loading" class="max-h-64 space-y-2 overflow-y-auto">
                <template x-for="article in filteredArticles" :key="article.id">
                    <div
                        class="flex items-center justify-between rounded-lg border p-3 transition"
                        :class="selectedArticleIds.includes(article.id) ? 'border-primary-300 bg-primary-50 dark:border-primary-700 dark:bg-primary-950' : 'border-gray-200 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800'"
                    >
                        <div class="flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white" x-text="article.description"></p>
                            <div class="mt-1 flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                                <span x-show="article.article_code" x-text="'Code: ' + article.article_code"></span>
                                <span x-text="formatCurrency(article.unit_price) + ' / ' + article.unit_type"></span>
                                <span
                                    x-show="article.is_parent_article"
                                    class="inline-flex items-center rounded bg-success-100 px-2 py-0.5 text-xs font-medium text-success-800 dark:bg-success-900 dark:text-success-200"
                                >
                                    Parent Article
                                </span>
                            </div>
                        </div>
                        <x-filament::button
                            type="button"
                            size="xs"
                            @click="addArticle(article)"
                            x-bind:disabled="selectedArticleIds.includes(article.id)"
                        >
                            <span x-show="!selectedArticleIds.includes(article.id)">Add</span>
                            <span x-show="selectedArticleIds.includes(article.id)">Added</span>
                        </x-filament::button>
                    </div>
                </template>
                
                {{-- No Results --}}
                <div x-show="filteredArticles.length === 0" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                    No articles found
                </div>
            </div>
        </div>

        {{-- Selected Articles --}}
        <div class="rounded-lg border border-gray-300 bg-white p-4 dark:border-gray-600 dark:bg-gray-900">
            <div class="mb-3 flex items-center justify-between">
                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Selected Articles</h4>
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400" x-text="articles.length + ' article(s)'"></span>
            </div>
            
            {{-- Empty State --}}
            <div x-show="articles.length === 0" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                No articles selected yet. Search and add articles above.
            </div>
            
            {{-- Articles Table --}}
            <div x-show="articles.length > 0" class="space-y-2">
                <template x-for="(article, index) in articles" :key="index">
                    <div
                        class="flex items-center gap-3 rounded-lg border p-3"
                        :class="article.is_child ? 'ml-6 border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800' : 'border-gray-300 dark:border-gray-600'"
                    >
                        {{-- Article Info --}}
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <span
                                    x-show="article.is_child"
                                    class="text-gray-400"
                                >
                                    ↳
                                </span>
                                <p class="text-sm font-medium text-gray-900 dark:text-white" x-text="article.description"></p>
                            </div>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" x-show="article.article_code" x-text="'Code: ' + article.article_code"></p>
                        </div>
                        
                        {{-- Quantity Input --}}
                        <div class="w-20">
                            <x-filament::input
                                type="number"
                                min="1"
                                x-bind:value="article.quantity"
                                @input="updateQuantity(index, $event.target.value)"
                                class="text-sm"
                            />
                        </div>
                        
                        {{-- Price Input --}}
                        <div class="w-32">
                            <x-filament::input
                                type="number"
                                step="0.01"
                                min="0"
                                x-bind:value="article.unit_price"
                                @input="updatePrice(index, $event.target.value)"
                                class="text-sm"
                            />
                        </div>
                        
                        {{-- Line Total --}}
                        <div class="w-28 text-right">
                            <p class="text-sm font-medium text-gray-900 dark:text-white" x-text="formatCurrency(article.unit_price * article.quantity)"></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400" x-text="article.unit_type"></p>
                        </div>
                        
                        {{-- Remove Button --}}
                        <x-filament::icon-button
                            icon="heroicon-o-trash"
                            color="danger"
                            size="sm"
                            @click="removeArticle(index)"
                            x-bind:disabled="article.is_child && articles.some(a => a.id === article.parent_id)"
                            x-bind:title="article.is_child ? 'Remove parent to remove this child' : 'Remove article'"
                        />
                    </div>
                </template>
                
                {{-- Subtotal --}}
                <div class="mt-4 flex justify-between border-t border-gray-300 pt-3 dark:border-gray-600">
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Articles Subtotal:</span>
                    <span class="text-base font-bold text-primary-600 dark:text-primary-400" x-text="formatCurrency(calculateSubtotal())"></span>
                </div>
            </div>
        </div>
        
        {{-- Help Text --}}
        <div class="rounded-lg bg-info-50 p-3 dark:bg-info-950">
            <p class="text-xs text-info-700 dark:text-info-300">
                <strong>Tip:</strong> When you add a parent article, all related surcharges (child articles) will be automatically added. 
                You can adjust quantities and prices for each article individually.
            </p>
        </div>
    </div>
</x-dynamic-component>

