{{--
    What an optional part costs in memory now, and when nothing uses it,
    what turning it off gives back.

    @param int|null $bytes     its services' memory (null: unknown)
    @param bool     $unused    installed, running, used by nothing
--}}
@if ($bytes !== null && ($bytes > 0 || $unused))
    <p @class(['mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm', 'text-gray-600 dark:text-gray-300' => ! $unused, 'text-warning-700 dark:text-warning-400' => $unused])>
        {!! icon('outline:cpu-chip', 'h-4 w-4 shrink-0') !!}
        <span>{{ __('Paměť teď: :size', ['size' => \Illuminate\Support\Number::fileSize($bytes, 0)]) }}</span>
        @if ($unused)
            <span>· {{ __('nic ho nepoužívá, odinstalováním uvolníte tuto paměť.') }}</span>
        @endif
    </p>
@endif
