<div class="space-y-4">
    <div class="flex items-center gap-3">
        {{-- POR (Place of Receipt) --}}
        <div class="flex-1">
            <x-filament::input.wrapper>
                <x-filament::input
                    type="text"
                    wire:model.live="data.por"
                    placeholder="Place of Receipt (optional)"
                />
            </x-filament::input.wrapper>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">POR (optional)</p>
        </div>

        {{-- Arrow --}}
        <div class="flex-shrink-0 pt-6">
            <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
            </svg>
        </div>

        {{-- POL (Port of Loading) --}}
        <div class="flex-1">
            <x-filament::input.wrapper>
                <x-filament::input
                    type="text"
                    wire:model.live="data.pol"
                    placeholder="Port of Loading *"
                    required
                />
            </x-filament::input.wrapper>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">POL (required)</p>
        </div>

        {{-- Arrow --}}
        <div class="flex-shrink-0 pt-6">
            <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
            </svg>
        </div>

        {{-- POD (Port of Discharge) --}}
        <div class="flex-1">
            <x-filament::input.wrapper>
                <x-filament::input
                    type="text"
                    wire:model.live="data.pod"
                    placeholder="Port of Discharge *"
                    required
                />
            </x-filament::input.wrapper>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">POD (required)</p>
        </div>

        {{-- Arrow --}}
        <div class="flex-shrink-0 pt-6">
            <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
            </svg>
        </div>

        {{-- FDEST (Final Destination) --}}
        <div class="flex-1">
            <x-filament::input.wrapper>
                <x-filament::input
                    type="text"
                    wire:model.live="data.fdest"
                    placeholder="Final Destination (optional)"
                />
            </x-filament::input.wrapper>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">FDEST (optional)</p>
        </div>
    </div>

    {{-- In Transit To --}}
    <div class="mt-4">
        <div class="flex-1">
            <x-filament::input.wrapper>
                <x-filament::input
                    type="text"
                    wire:model.live="data.in_transit_to"
                    placeholder="In Transit To (optional)"
                />
            </x-filament::input.wrapper>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                In Transit To (optional) - If shipment is in transit to another country, specify destination
            </p>
        </div>
    </div>

    {{-- Helper Text --}}
    <div class="rounded-lg bg-info-50 p-3 dark:bg-info-950">
        <p class="text-xs text-info-700 dark:text-info-300">
            <strong>Note:</strong> For port-to-port shipments, only POL and POD are required. 
            POR and FDEST are optional for door-to-door or combined transport services.
        </p>
    </div>
</div>

