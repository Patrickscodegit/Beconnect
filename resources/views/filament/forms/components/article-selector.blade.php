<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        x-data="{
            articles: @entangle($getStatePath()),
            availableArticles: [],
            searchQuery: '',
            loading: true,
            selectedArticleIds: [],
            
            init() {
                this.loadArticles();
                this.articles = this.articles || [];
                this.updateSelectedIds();
            },
            
            async loadArticles() {
                this.loading = true;
                try {
                    const carrierCode = '{{ $getCarrierCode() }}';
                    const url = '/admin/api/quotation/articles?service_type={{ $getServiceType() }}&customer_type={{ $getCustomerType() }}' + 
                                (carrierCode ? '&carrier_code=' + carrierCode : '');
                    const response = await fetch(url);
                    if (response.ok) {
                        this.availableArticles = await response.json();
                    }
                } catch (error) {
                    console.error('Failed to load articles:', error);
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
        {{-- Article Search and Add --}}
        <div class="rounded-lg border border-gray-300 bg-white p-4 dark:border-gray-600 dark:bg-gray-900">
            <h4 class="mb-3 text-sm font-semibold text-gray-700 dark:text-gray-300">Add Articles</h4>
            
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

