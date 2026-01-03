<x-filament-panels::page>
    <div class="space-y-6">
        {{ $this->form }}

        <x-filament::section>
            <x-slot name="heading">
                Analyze Input
            </x-slot>
            <x-slot name="description">
                Analyze port strings to find resolved ports and unresolved tokens.
            </x-slot>

            <div class="space-y-4">
                <x-filament::button wire:click="analyze" color="primary">
                    Analyze
                </x-filament::button>

                @if(!empty($results))
                    <div class="space-y-4">
                        @foreach($results as $lineIndex => $result)
                            <div class="border rounded-lg p-4 space-y-2">
                                <div class="font-semibold">Line: {{ $result['line'] }}</div>

                                @if(!empty($result['ports']))
                                    <div>
                                        <span class="text-sm font-medium text-success-600">Resolved:</span>
                                        <ul class="list-disc list-inside ml-4">
                                            @foreach($result['ports'] as $port)
                                                <li>{{ $port['label'] }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                @if(!empty($result['unresolved']))
                                    <div>
                                        <span class="text-sm font-medium text-warning-600">Unresolved:</span>
                                        <div class="space-y-2 mt-2">
                                            @foreach($result['unresolved'] as $token)
                                                <div class="border rounded p-3 space-y-2">
                                                    <div class="font-medium">{{ $token }}</div>
                                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                                                        <div>
                                                            <label class="text-xs text-gray-500">Port</label>
                                                            <select 
                                                                wire:model.live="results.{{ $lineIndex }}.mappings.{{ $token }}.port_id"
                                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
                                                            >
                                                                <option value="">Select port...</option>
                                                                @foreach($this->getPortOptions() as $portId => $portLabel)
                                                                    <option value="{{ $portId }}">{{ $portLabel }}</option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                        <div>
                                                            <label class="text-xs text-gray-500">Alias Type</label>
                                                            <select 
                                                                wire:model.live="results.{{ $lineIndex }}.mappings.{{ $token }}.alias_type"
                                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
                                                            >
                                                                <option value="name_variant">Name Variant</option>
                                                                <option value="code_variant">Code Variant</option>
                                                                <option value="typo">Typo</option>
                                                                <option value="unlocode">UN/LOCODE</option>
                                                            </select>
                                                        </div>
                                                        <div class="flex items-end">
                                                            <label class="flex items-center space-x-2">
                                                                <input 
                                                                    type="checkbox" 
                                                                    wire:model.live="results.{{ $lineIndex }}.mappings.{{ $token }}.is_active"
                                                                    class="rounded border-gray-300"
                                                                >
                                                                <span class="text-xs text-gray-500">Active</span>
                                                            </label>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <x-filament::button 
                                                            wire:click="createSingleAlias({{ $lineIndex }}, '{{ $token }}')"
                                                            size="sm"
                                                            color="success"
                                                        >
                                                            Create Alias
                                                        </x-filament::button>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endforeach

                        <div class="pt-4">
                            <x-filament::button 
                                wire:click="createAllAliases"
                                color="primary"
                                size="lg"
                            >
                                Create All Mapped Aliases
                            </x-filament::button>
                        </div>
                    </div>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                Load from Audit JSON
            </x-slot>
            <x-slot name="description">
                Paste the output of <code>ports:audit-normalization --json</code> to load unresolved inputs.
            </x-slot>

            <div class="space-y-4">
                <textarea 
                    wire:model="auditJson"
                    rows="10"
                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
                    placeholder='Paste JSON output from ports:audit-normalization --json'
                ></textarea>

                <x-filament::button wire:click="loadFromAuditJson" color="secondary">
                    Load Unresolved Tokens
                </x-filament::button>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>

