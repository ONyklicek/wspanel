@php
    // The tag itself belongs to the toolkit's renderer (`@packageScripts` below),
    // which owns delivery and the attributes the declaration carries. The path is
    // still needed here to choose the branch, and to read the source for the
    // fallback when the compiled bundle is absent.
    $notificationAssetFile = \NyonCode\WireCore\WireCoreServiceProvider::ASSETS_PATH.'/wire-core-notifications.js';
@endphp

{{-- Pre-bundled live bridge (wireNotificationLive). Included only by a bell that
     has a channel to listen on, so an application that does not broadcast its
     notifications carries neither the bundle nor a channel to authorize.

     Through Livewire's @assets for the same reason as every other bundle: the
     script has to register once, and has to run when the bell arrives on a
     `wire:navigate` visit, where a DOM-morphed <script> would never execute. --}}
@assets
@if(is_file($notificationAssetFile))
@packageScripts('wire-core', 'wire-core-notifications.js')
@else
{{-- The x-data on the bell's wrapper references the factory either way, and a
     dangling reference would take the whole bell down with it, silently. The
     source is import-free on purpose so it can stand in verbatim, and is wrapped
     in an IIFE because that is what esbuild does to the same file for the bundle
     above: inlined bare, its top-level declarations would land in the document's
     global lexical scope. --}}
<script>(function () {
{!! file_get_contents(dirname($notificationAssetFile, 2).'/resources/js/notification-live.js') !!}
})();</script>
@endif
@endassets
