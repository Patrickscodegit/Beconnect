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
        @script
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // #region agent log
                fetch('http://127.0.0.1:7242/ingest/d7bc2db0-7e0d-4c45-a5aa-d8d699f437b8', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        sessionId: 'debug-session',
                        runId: 'run1',
                        hypothesisId: 'A',
                        location: 'edit-carrier-rule.blade.php:script',
                        message: 'Auto-focus script started',
                        data: {mappingId: {{ $this->focusMappingId }} },
                        timestamp: Date.now()
                    })
                }).catch(() => {});
                // #endregion

                const mappingId = '{{ $this->focusMappingId }}';
                
                // #region agent log
                fetch('http://127.0.0.1:7242/ingest/d7bc2db0-7e0d-4c45-a5aa-d8d699f437b8', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        sessionId: 'debug-session',
                        runId: 'run1',
                        hypothesisId: 'A',
                        location: 'edit-carrier-rule.blade.php:script',
                        message: 'Looking for element',
                        data: {mappingId: mappingId, selector: '[data-mapping-id="' + mappingId + '"]' },
                        timestamp: Date.now()
                    })
                }).catch(() => {});
                // #endregion
                
                // Find the repeater item with this mapping ID
                const element = document.querySelector('[data-mapping-id="' + mappingId + '"]');
                
                // #region agent log
                fetch('http://127.0.0.1:7242/ingest/d7bc2db0-7e0d-4c45-a5aa-d8d699f437b8', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        sessionId: 'debug-session',
                        runId: 'run1',
                        hypothesisId: 'A',
                        location: 'edit-carrier-rule.blade.php:script',
                        message: 'Element found',
                        data: {elementFound: !!element, elementTag: element ? element.tagName : null },
                        timestamp: Date.now()
                    })
                }).catch(() => {});
                // #endregion
                
                if (element) {
                    // Scroll to element
                    element.scrollIntoView({ 
                        behavior: 'smooth', 
                        block: 'center' 
                    });
                    
                    // Add highlight
                    element.classList.add('ring-2', 'ring-primary-500', 'rounded-md');
                    
                    // #region agent log
                    fetch('http://127.0.0.1:7242/ingest/d7bc2db0-7e0d-4c45-a5aa-d8d699f437b8', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            sessionId: 'debug-session',
                            runId: 'run1',
                            hypothesisId: 'A',
                            location: 'edit-carrier-rule.blade.php:script',
                            message: 'Scrolled and highlighted',
                            data: {},
                            timestamp: Date.now()
                        })
                    }).catch(() => {});
                    // #endregion
                    
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
                            
                            // #region agent log
                            fetch('http://127.0.0.1:7242/ingest/d7bc2db0-7e0d-4c45-a5aa-d8d699f437b8', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({
                                    sessionId: 'debug-session',
                                    runId: 'run1',
                                    hypothesisId: 'A',
                                    location: 'edit-carrier-rule.blade.php:script',
                                    message: 'Expanded collapsed section',
                                    data: {},
                                    timestamp: Date.now()
                                })
                            }).catch(() => {});
                            // #endregion
                        }
                    }
                } else {
                    // #region agent log
                    fetch('http://127.0.0.1:7242/ingest/d7bc2db0-7e0d-4c45-a5aa-d8d699f437b8', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            sessionId: 'debug-session',
                            runId: 'run1',
                            hypothesisId: 'A',
                            location: 'edit-carrier-rule.blade.php:script',
                            message: 'Element not found',
                            data: {mappingId: mappingId },
                            timestamp: Date.now()
                        })
                    }).catch(() => {});
                    // #endregion
                }
            });
        </script>
        @endscript
    @endif
</x-filament-panels::page>
