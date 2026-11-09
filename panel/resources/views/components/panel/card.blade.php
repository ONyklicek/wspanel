@props(['heading', 'description' => null, 'flush' => false, 'actions' => []])
{{-- A section card with its own actions in the header (top right) and an
     optional status next to the heading: the one shape of the panel's
     hand-made sections. `flush` = the body has no padding (a table). --}}
<div {{ $attributes->class(['rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800']) }}>
    <div class="flex flex-col gap-3 border-b border-gray-200 px-4 py-4 sm:flex-row sm:items-start sm:justify-between sm:px-6 dark:border-gray-700">
        <div class="min-w-0 flex-1">
            <h3 class="flex flex-wrap items-center gap-2 text-base font-semibold text-gray-900 dark:text-white">
                {{ $heading }}
                @isset($status){{ $status }}@endisset
            </h3>
            @if ($description)
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
            @endif
        </div>
        @php($actions = array_filter($actions))
        @if ($actions !== [] || isset($buttons))
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @foreach ($actions as $action)
                    <x-wire-actions::button :action="$action" />
                @endforeach
                {{ $buttons ?? '' }}
            </div>
        @endif
    </div>
    @if (trim((string) $slot) !== '')
        <div @class(['px-4 py-4 sm:px-6' => ! $flush])>
            {{ $slot }}
        </div>
    @endif
    @isset($footer)
        <div class="border-t border-gray-100 px-4 py-3 text-xs text-gray-500 sm:px-6 dark:border-white/5">{{ $footer }}</div>
    @endisset
</div>
