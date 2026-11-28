{{-- The layout your full-page components render in.

     Written by `php artisan wire-admin:install`, and yours from here: the frame,
     the sidebar over your registered resources, the palette and the toasts are
     the package's; what goes in the slots is yours. --}}
<x-wire-admin::layout :title="$title ?? config('app.name')">
    <x-slot:head>
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </x-slot:head>

    {{-- <x-slot:user><x-app-user-menu /></x-slot:user> --}}

    @if (session()->has(\App\Actions\Clients\Impersonation::SESSION_KEY))
        <div data-wire="impersonation-banner" class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
            <span>{{ __('Prohlížíte panel jako klient') }} <strong>{{ auth()->user()?->client?->name }}</strong> ({{ auth()->user()?->email }}{{ __('). Vidíte a smíte jen to, co on.') }}</span>
            <form method="POST" action="{{ route('impersonation.leave') }}">
                @csrf
                <button type="submit" class="rounded-md bg-amber-600 px-3 py-1.5 font-medium text-white hover:bg-amber-700">{{ __('Vrátit se ke správě') }}</button>
            </form>
        </div>
    @endif

    {{-- A mirror's standby (or a fenced old primary): its panel manages the
         server itself; the sites are managed where they are served. --}}
    @php($activePanel = auth()->check() && \Illuminate\Support\Facades\Gate::allows('admin') ? app(\App\Actions\Server\ManageMirror::class)->activePanelUrl() : null)
    @if ($activePanel !== null)
        <div data-wire="mirror-standby-banner" class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-sky-300 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-700 dark:bg-sky-950 dark:text-sky-100">
            <span>{{ __('Tento server je zrcadlo. Spravujete tu server samotný (aktualizace, moduly, služby, zrcadlo). Weby, databáze a poštu spravujete na aktivním serveru: co by vzniklo tady, se při převzetí přepíše.') }}</span>
            @if ($activePanel !== '')
                <a href="{{ $activePanel }}" class="rounded-md bg-sky-600 px-3 py-1.5 font-medium text-white hover:bg-sky-700">{{ __('Otevřít panel aktivního serveru') }}</a>
            @endif
        </div>
    @endif

    {{ $slot }}
</x-wire-admin::layout>
