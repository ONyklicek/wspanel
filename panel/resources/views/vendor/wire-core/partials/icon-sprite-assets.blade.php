{{-- The browser half of the icon sprite (`wire-core-icons.js`): keeps every
     `<symbol>` the page has seen in one sprite at the end of <body>, so a
     `<use>` still resolves after the icon that carried its symbol was morphed
     away.

     Not included by any surface: `Foundation\Icons\IconSpriteHook` hands this to
     Livewire's asset registry the first time a component renders a sprited icon
     — the icons are everywhere, so no single view could own the include. Served
     by `Bundle::serve()`'s route for the reason the sortable list is: declaring
     it would put it in the <head> of every page whether sprites are on or not. --}}
@php
    $iconSpriteFile = \NyonCode\WireCore\WireCoreServiceProvider::ASSETS_PATH.'/wire-core-icons.js';
    $iconSpriteUrl = route('wire-core.asset', ['asset' => 'icons'])
        .(is_file($iconSpriteFile) ? '?id='.filemtime($iconSpriteFile) : '');
@endphp
<script src="{{ $iconSpriteUrl }}" data-navigate-once></script>
