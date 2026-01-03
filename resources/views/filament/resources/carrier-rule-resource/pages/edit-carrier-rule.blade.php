<x-filament-panels::page
    @class([
        'fi-resource-edit-record-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
        'fi-resource-record-' . $record->getKey(),
    ])
>
    @capture($form)
        <x-filament-panels::form
            id="form"
            :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
            wire:submit="save"
        >
            {{ $this->form }}

            <x-filament-panels::form.actions
                :actions="$this->getCachedFormActions()"
                :full-width="$this->hasFullWidthFormActions()"
            />
        </x-filament-panels::form>
    @endcapture

    @php
        $relationManagers = $this->getRelationManagers();
        $hasCombinedRelationManagerTabsWithContent = $this->hasCombinedRelationManagerTabsWithContent();
    @endphp

    @if ((! $hasCombinedRelationManagerTabsWithContent) || (! count($relationManagers)))
        {{ $form() }}
    @endif

    @if (count($relationManagers))
        <x-filament-panels::resources.relation-managers
            :active-locale="isset($activeLocale) ? $activeLocale : null"
            :active-manager="$this->activeRelationManager ?? ($hasCombinedRelationManagerTabsWithContent ? null : array_key_first($relationManagers))"
            :content-tab-label="$this->getContentTabLabel()"
            :content-tab-icon="$this->getContentTabIcon()"
            :content-tab-position="$this->getContentTabPosition()"
            :managers="$relationManagers"
            :owner-record="$record"
            :page-class="static::class"
        >
            @if ($hasCombinedRelationManagerTabsWithContent)
                <x-slot name="content">
                    {{ $form() }}
                </x-slot>
            @endif
        </x-filament-panels::resources.relation-managers>
    @endif

    <x-filament-panels::page.unsaved-data-changes-alert />

    @if($this->focusMappingId)
        <div 
            x-data='{ 
                mappingId: {{ $this->focusMappingId ?? 'null' }},
                init() {
                    // Try clicking the tab
                    // Try multiple times with increasing delays to catch tabs after Livewire renders
                    [100, 300, 500, 1000, 2000].forEach(delay => {
                        setTimeout(() => this.switchToTab(), delay);
                    });
                },
                switchToTab() {
                    // Try multiple selectors for Filament tabs
                    const selectors = [
                        ".fi-tabs button",
                        "[role=\"tab\"]",
                        "button[type=\"button\"]",
                        ".fi-tabs [role=\"tablist\"] button",
                        "button.fi-tabs-tab",
                        "[data-tab]"
                    ];
                    
                    let tabs = [];
                    for (const selector of selectors) {
                        tabs = document.querySelectorAll(selector);
                        if (tabs.length > 0) break;
                    }
                    
                    // Find Freight Mapping tab
                    let targetTab = null;
                    for (const tab of tabs) {
                        const text = tab.textContent?.trim() || "";
                        const ariaLabel = tab.getAttribute("aria-label") || "";
                        const dataTab = tab.getAttribute("data-tab") || "";
                        
                        if (text.includes("Freight Mapping") || 
                            ariaLabel.includes("Freight Mapping") ||
                            dataTab === "article_mappings") {
                            targetTab = tab;
                            break;
                        }
                    }
                    
                    if (targetTab) {
                        // Try multiple click methods
                        targetTab.click();
                        // Also try dispatching a click event
                        targetTab.dispatchEvent(new MouseEvent("click", {
                            bubbles: true,
                            cancelable: true,
                            view: window
                        }));
                        // Also try mousedown + mouseup
                        targetTab.dispatchEvent(new MouseEvent("mousedown", { bubbles: true }));
                        targetTab.dispatchEvent(new MouseEvent("mouseup", { bubbles: true }));
                        
                        setTimeout(() => this.focusMapping(), 800);
                        return true;
                    }
                    
                    return false;
                },
                focusMapping() {
                    const element = document.querySelector("[data-mapping-id=\"" + this.mappingId + "\"]");
                    if (element) {
                        element.scrollIntoView({ behavior: "smooth", block: "center" });
                        element.classList.add("ring-2", "ring-primary-500", "rounded-md");
                        setTimeout(() => element.classList.remove("ring-2", "ring-primary-500", "rounded-md"), 3000);
                    }
                }
            }'
            style="display: none;"
        ></div>
    @endif
    
    @if($this->focusMappingId)
        <script>
            function switchToFreightMappingTab() {
                // Find all tab buttons - try multiple selectors
                const allTabButtons = document.querySelectorAll('.fi-tabs button, [role="tab"], button[type="button"], .fi-tabs [role="tablist"] button');
                
                // Find the "Freight Mapping" tab - prioritize data-tab attribute, then exact text match
                let tabButton = null;
                for (const btn of allTabButtons) {
                    // First check for data-tab attribute (most reliable)
                    const dataTab = btn.getAttribute('data-tab');
                    if (dataTab === 'article_mappings') {
                        tabButton = btn;
                        break;
                    }
                }
                
                // If not found by data-tab, try exact text match (exclude "Sort Freight Mappings")
                if (!tabButton) {
                    for (const btn of allTabButtons) {
                        const text = btn.textContent?.trim() || '';
                        // Match exactly "Freight Mapping" (not "Sort Freight Mappings")
                        if (text === 'Freight Mapping' || (text.includes('Freight Mapping') && !text.includes('Sort'))) {
                            // Also check it's actually a tab (has role="tab" or is in .fi-tabs)
                            const role = btn.getAttribute('role');
                            const isInTabs = btn.closest('.fi-tabs');
                            if (role === 'tab' || isInTabs) {
                                tabButton = btn;
                                break;
                            }
                        }
                    }
                }
                
                if (tabButton) {
                    // Try multiple click methods
                    tabButton.click();
                    
                    // Also try dispatching events
                    tabButton.dispatchEvent(new MouseEvent('click', {
                        bubbles: true,
                        cancelable: true,
                        view: window
                    }));
                    
                    // Try mousedown + mouseup
                    tabButton.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
                    tabButton.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
                    
                    // If Livewire is available, try setting the tab state directly
                    if (typeof Livewire !== 'undefined' && typeof $wire !== 'undefined') {
                        try {
                            $wire.set('data.carrier_rules_tabs', 'article_mappings');
                        } catch (e) {
                            // Silently fail if Livewire is not available
                        }
                    }
                    
                    return true;
                }
                return false;
            }
            
            function initTabSwitch() {
                
                // Try immediately
                if (switchToFreightMappingTab()) {
                    setTimeout(() => focusMapping(), 500);
                    return;
                }
                
                // If tabs not found, wait and retry
                let attempts = 0;
                const maxAttempts = 10;
                const checkInterval = setInterval(() => {
                    attempts++;
                    if (switchToFreightMappingTab() || attempts >= maxAttempts) {
                        clearInterval(checkInterval);
                        if (attempts < maxAttempts) {
                            setTimeout(() => focusMapping(), 500);
                        } else {
                            focusMapping(); // Try to focus anyway
                        }
                    }
                }, 200);
            }
            
            // Try multiple initialization strategies
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initTabSwitch);
            } else {
                // DOM already loaded
                if (typeof Livewire !== 'undefined') {
                    Livewire.hook('mounted', () => {
                        setTimeout(initTabSwitch, 100);
                    });
                }
                // Also try immediately
                setTimeout(initTabSwitch, 100);
            }
            
            // Fallback: try after a delay
            setTimeout(initTabSwitch, 1000);
            
            function focusMapping() {
                const mappingId = {{ $this->focusMappingId ?? 'null' }};
                
                // Find the repeater item with this mapping ID
                const element = document.querySelector('[data-mapping-id="' + mappingId + '"]');
                
                if (element) {
                    // Scroll to element
                    element.scrollIntoView({ 
                        behavior: 'smooth', 
                        block: 'center' 
                    });
                    
                    // Add highlight
                    element.classList.add('ring-2', 'ring-primary-500', 'rounded-md');
                    
                    // Remove highlight after 3 seconds
                    setTimeout(() => {
                        element.classList.remove('ring-2', 'ring-primary-500', 'rounded-md');
                    }, 3000);
                    
                    // Attempt to expand collapsed section if the repeater is in a collapsed Section
                    const collapsible = element.closest('.fi-section-collapsible, [data-collapsible]');
                    if (collapsible) {
                        const toggle = collapsible.querySelector('[data-collapsible-toggle], .fi-section-header-button');
                        if (toggle && collapsible.classList.contains('collapsed')) {
                            toggle.click();
                        }
                    }
                }
            }
        </script>
    @endif
</x-filament-panels::page>
