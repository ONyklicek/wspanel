@php use NyonCode\WireCore\Foundation\Support\MobileSheet; @endphp
@include('wire-core::partials.floating-assets')

<div
    @wireEl('dropdown')
    x-data="wireDropdown({ placement: '{{ $position }}'{{ $sheetOnMobile ? ', sheetOnMobile: true, sheetBreakpoint: '.MobileSheet::px($breakpoint) : '' }} })"
    @keydown.escape.window="close()"
    class="relative inline-block text-left"
    {{ $attributes }}
>
    <div x-ref="trigger" x-on:click="toggle()">
        {{ $trigger ?? '' }}
    </div>

    {{-- Teleported to <body>. From sm up it floats next to the trigger (Floating
         UI); with sheetOnMobile it becomes a bottom sheet on a phone (max-sm:
         classes, Floating UI skipped) with a dimming backdrop. --}}
    <template x-teleport="body">
        <div>
            @if($sheetOnMobile)
                {{-- Backdrop: mobile-only, taps to close. --}}
                <div
                    x-show="open"
                    x-cloak
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    @click="close()"
                    class="fixed inset-0 z-40 bg-gray-500/60 dark:bg-gray-900/70 {{ MobileSheet::backdropHide($breakpoint) }}"
                ></div>
            @endif

            <div
                x-ref="panel"
                x-show="open"
                @click.outside="$clickedInside($event) || close()"
                @if($sheetOnMobile) x-focus-trap="open" tabindex="-1" data-sheet-bp="{{ MobileSheet::px($breakpoint) }}" @endif
                x-transition:enter="transition ease-out duration-100"
                x-transition:enter-start="opacity-0 scale-95 {{ $sheetOnMobile ? MobileSheet::motion($breakpoint) : '' }}"
                x-transition:enter-end="opacity-100 scale-100 {{ $sheetOnMobile ? 'translate-y-0' : '' }}"
                x-transition:leave="transition ease-in duration-75"
                x-transition:leave-start="opacity-100 scale-100 {{ $sheetOnMobile ? 'translate-y-0' : '' }}"
                x-transition:leave-end="opacity-0 scale-95 {{ $sheetOnMobile ? MobileSheet::motion($breakpoint) : '' }}"
                x-cloak
                @class([
                    $width . ' absolute top-0 left-0 z-50 rounded-lg bg-white shadow-lg ring-1 ring-black/5 dark:bg-gray-800 dark:ring-white/10',
                    'origin-top-right' => $position === 'bottom-end',
                    'origin-top-left' => $position === 'bottom-start',
                    MobileSheet::panel($breakpoint) => $sheetOnMobile,
                ])
                style="display: none;"
            >
                @if($sheetOnMobile)
                    @include('wire-core::partials.sheet-grabber', ['dismiss' => 'close()', 'breakpoint' => $breakpoint])
                @endif
                <div class="py-1">
                    {{ $slot }}
                </div>
            </div>
        </div>
    </template>
</div>
