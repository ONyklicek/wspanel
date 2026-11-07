@php
    use NyonCode\WireCore\Foundation\View\Palette;
@endphp
{{-- Polling Indicator --}}
@php
    $pollingConfig = $component->getTablePollingConfig();
@endphp

@if($pollingConfig['enabled'] ?? false)
    <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
        @if($pollingConfig['active'] ?? false)
            {{-- Active indicator --}}
            <span class="relative flex h-2 w-2">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full {{ Palette::getAccentBgClass('success') }} opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 {{ Palette::getAccentBgClass('success') }}"></span>
            </span>
            <span>Auto {{ $pollingConfig['interval'] ?? '5s' }}</span>
        @else
            {{-- Paused indicator --}}
            <span class="relative flex h-2 w-2">
                <span class="relative inline-flex rounded-full h-2 w-2 bg-gray-400"></span>
            </span>
            <span>{{ __('wire-table::messages.paused') }}</span>
        @endif

        <button
            type="button"
            wire:click="toggleTablePolling" data-testid="polling-toggle" @wireEl('polling-toggle')
            class="text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 underline decoration-dotted underline-offset-2"
        >
            {{ ($pollingConfig['active'] ?? false) ? __('wire-table::messages.stop') : __('wire-table::messages.start') }}
        </button>
    </div>
@endif
