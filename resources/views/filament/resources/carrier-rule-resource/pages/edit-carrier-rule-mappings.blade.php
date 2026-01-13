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
        <script>
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
            
            // Try to focus mapping when page loads
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => {
                    setTimeout(focusMapping, 500);
                });
            } else {
                setTimeout(focusMapping, 500);
            }
            
            // Also try after Livewire mounts
            if (typeof Livewire !== 'undefined') {
                Livewire.hook('mounted', () => {
                    setTimeout(focusMapping, 500);
                });
            }
        </script>
    @endif
    
    {{-- Handle hash fragment for creating new mappings --}}
    @if(request()->has('port_code') || request()->has('category'))
        <script>
            // Auto-add new mapping when port_code or category is provided
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(function() {
                    // Find the "Add item" button in the repeater
                    const addButton = document.querySelector('[wire\\:id*="articleMappings"]')?.closest('.fi-repeater')?.querySelector('button[type="button"]');
                    if (addButton && addButton.textContent?.includes('Add')) {
                        addButton.click();
                    }
                }, 1000);
            });
        </script>
    @endif
</x-filament-panels::page>
