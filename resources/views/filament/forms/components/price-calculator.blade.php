<div
    x-data="{
        subtotal: 0,
        roleMargin: 0,
        roleMarginPercentage: 0,
        discount: 0,
        vatRate: 21,
        subtotalExclVat: 0,
        vatAmount: 0,
        totalInclVat: 0,
        
        init() {
            // Initialize values from form data
            this.discount = parseFloat($wire.get('data.discount_percentage')) || 0;
            this.vatRate = parseFloat($wire.get('data.vat_rate')) || 21;
            
            this.calculatePricing();
            
            // Listen for article changes
            this.$watch('$wire.data.articles', () => {
                this.calculatePricing();
            });
            
            // Listen for discount changes
            this.$watch('$wire.data.discount_percentage', (value) => {
                this.discount = parseFloat(value) || 0;
                this.calculatePricing();
            });
            
            // Listen for VAT rate changes
            this.$watch('$wire.data.vat_rate', (value) => {
                this.vatRate = parseFloat(value) || 21;
                this.calculatePricing();
            });
            
            // Listen for role changes
            this.$watch('$wire.data.customer_role', () => {
                this.calculatePricing();
            });
        },
        
        calculatePricing() {
            // Get articles from form data
            const articles = $wire.get('data.articles') || [];
            
            // Calculate subtotal from articles
            this.subtotal = articles.reduce((sum, article) => {
                const price = parseFloat(article.unit_price ?? article.price) || 0;
                const quantity = parseFloat(article.quantity) || 1;
                return sum + (price * quantity);
            }, 0);
            
            // Get customer role margin from config
            const customerRole = $wire.get('data.customer_role');
            this.roleMarginPercentage = this.getRoleMargin(customerRole);
            this.roleMargin = this.subtotal * (this.roleMarginPercentage / 100);
            
            // Calculate subtotal with margin
            const subtotalWithMargin = this.subtotal + this.roleMargin;
            
            // Apply discount
            const discountAmount = subtotalWithMargin * (this.discount / 100);
            this.subtotalExclVat = subtotalWithMargin - discountAmount;
            
            // Calculate VAT
            this.vatAmount = this.subtotalExclVat * (this.vatRate / 100);
            
            // Calculate total
            this.totalInclVat = this.subtotalExclVat + this.vatAmount;
            
            // Update hidden fields
            $wire.set('data.subtotal', this.subtotal.toFixed(2));
            $wire.set('data.total_excl_vat', this.subtotalExclVat.toFixed(2));
            $wire.set('data.vat_amount', this.vatAmount.toFixed(2));
            $wire.set('data.total_incl_vat', this.totalInclVat.toFixed(2));
        },
        
        getRoleMargin(role) {
            // All margins set to 0 (temporarily disabled, will review later)
            const margins = {
                'RORO': 0,
                'POV': 0,
                'CONSIGNEE': 0,
                'FORWARDER': 0
            };
            return margins[role] || 0;
        },
        
        formatCurrency(amount) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'EUR'
            }).format(amount);
        }
    }"
    class="rounded-lg border border-gray-300 bg-gray-50 p-4 dark:border-gray-600 dark:bg-gray-800"
>
    <h4 class="mb-3 text-sm font-semibold text-gray-700 dark:text-gray-300">Pricing Calculation</h4>
    
    <div class="space-y-2">
        {{-- Subtotal from Articles --}}
        <div class="flex justify-between text-sm">
            <span class="text-gray-600 dark:text-gray-400">Subtotal (Articles):</span>
            <span class="font-medium text-gray-900 dark:text-white" x-text="formatCurrency(subtotal)"></span>
        </div>
        
        {{-- Role Margin --}}
        <div class="flex justify-between text-sm" x-show="roleMargin > 0">
            <span class="text-gray-600 dark:text-gray-400">
                Role Margin (<span x-text="roleMarginPercentage"></span>%):
            </span>
            <span class="font-medium text-success-600 dark:text-success-400" x-text="'+ ' + formatCurrency(roleMargin)"></span>
        </div>
        
        {{-- Discount --}}
        <div class="flex justify-between text-sm" x-show="discount > 0">
            <span class="text-gray-600 dark:text-gray-400">
                Discount (<span x-text="discount"></span>%):
            </span>
            <span class="font-medium text-danger-600 dark:text-danger-400" x-text="'- ' + formatCurrency((subtotal + roleMargin) * (discount / 100))"></span>
        </div>
        
        {{-- Divider --}}
        <div class="border-t border-gray-300 dark:border-gray-600"></div>
        
        {{-- Subtotal Excl VAT --}}
        <div class="flex justify-between text-sm">
            <span class="font-medium text-gray-700 dark:text-gray-300">Subtotal (excl. VAT):</span>
            <span class="font-semibold text-gray-900 dark:text-white" x-text="formatCurrency(subtotalExclVat)"></span>
        </div>
        
        {{-- VAT --}}
        <div class="flex justify-between text-sm">
            <span class="text-gray-600 dark:text-gray-400">
                VAT (<span x-text="vatRate"></span>%):
            </span>
            <span class="font-medium text-gray-900 dark:text-white" x-text="formatCurrency(vatAmount)"></span>
        </div>
        
        {{-- Divider --}}
        <div class="border-t-2 border-gray-400 dark:border-gray-500"></div>
        
        {{-- Total Incl VAT --}}
        <div class="flex justify-between">
            <span class="text-base font-bold text-gray-900 dark:text-white">
                <span x-show="vatAmount > 0">Total (incl. VAT):</span>
                <span x-show="vatAmount === 0">Total:</span>
            </span>
            <span class="text-lg font-bold text-primary-600 dark:text-primary-400" x-text="formatCurrency(totalInclVat)"></span>
        </div>
    </div>
    
    {{-- Info Message --}}
    <div class="mt-3 rounded bg-info-50 p-2 dark:bg-info-950">
        <p class="text-xs text-info-700 dark:text-info-300">
            Prices update automatically as you add or modify articles.
        </p>
    </div>
</div>

