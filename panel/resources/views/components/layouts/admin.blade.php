{{-- The layout your full-page components render in.

     Written by `php artisan wire-admin:install`, and yours from here: the frame,
     the sidebar over your registered resources, the palette and the toasts are
     the package's; what goes in the slots is yours. --}}
<x-wire-admin::layout :title="$title ?? config('app.name')">
    <x-slot:head>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </x-slot:head>

    <x-slot:brand>{{ config('app.name') }}</x-slot:brand>

    {{-- <x-slot:user><x-app-user-menu /></x-slot:user> --}}

    {{ $slot }}
</x-wire-admin::layout>
