{{-- The layout the signed-out screens render in.

     Written by `php artisan wire-module-auth:install`, and yours from here. The
     frame — the head, the theme decision before the first paint, the card — is
     the shell's; what goes in the slots is yours.

     This file is the reason it exists: a package cannot know your Vite entry
     names, so without a layout of your own the login page would load the
     framework's markup and none of your styles. --}}
@props(['title' => null])

<x-wire-admin::auth-layout :title="$title ?? config('app.name')">
    <x-slot:head>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </x-slot:head>

    {{-- The name above the card. Drop the slot to fall back to `app.name`. --}}
    <x-slot:brand>{{ config('app.name') }}</x-slot:brand>

    {{ $slot }}
</x-wire-admin::auth-layout>
