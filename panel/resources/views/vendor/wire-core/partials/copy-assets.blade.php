@php
    use NyonCode\WireCore\Foundation\View\Palette;
    // The tag itself belongs to the toolkit's renderer (`@packageScripts` below),
    // which owns delivery and the attributes the declaration carries. The path is
    // still needed here to choose the branch, and to read the source for the
    // fallback when the compiled bundle is absent.
    $copyAssetFile = \NyonCode\WireCore\WireCoreServiceProvider::ASSETS_PATH.'/wire-core-copy.js';
@endphp

{{-- Pre-bundled clipboard controller (copy.js), included once by any surface that
     has at least one copyable thing on it — a table's copyable column, an
     infolist's copyable entry. Through Livewire's @assets for the same
     reason as the other bundles: the script has to run once, and has to run when
     the table renders inside a Livewire-loaded modal, where a DOM-morphed <script>
     would never execute. --}}
@assets
@if(is_file($copyAssetFile))
@packageScripts('wire-core', 'wire-core-copy.js')
@else
{{-- Wrapped in an IIFE, which is what esbuild does to the same source for the
     bundle above (`--format=iife`): inlined bare, its top-level `const`/`let`
     would land in the document's global lexical scope, and a second copy would be
     a SyntaxError taking the whole script with it. The listener guard lives on
     `window` precisely so two copies still bind only once. --}}
<script>(function () {
{!! file_get_contents(dirname($copyAssetFile, 2).'/resources/js/copy.js') !!}
})();</script>
@endif
@endassets

{{-- The one feedback pill for the page. Rendered here rather than created in JS
     because a consumer's Tailwind scans `resources/views` and `src`, never `dist`
     — a class that existed only in the bundle would never reach their CSS.

     `@once` because this partial is included per copyable SURFACE, and a page can
     have several: a table includes it once for the whole table, but an infolist
     includes it per copyable entry, and two entries would otherwise mean two pills
     — the controller writes into the first one it finds, so the second would sit
     there dead. The script above is deduped by Livewire's `@assets`; this is the
     same guarantee for the markup.

     `hidden` and the viewport coordinates are driven by the controller; the
     transition is the browser's, so no Alpine is involved in showing it. --}}
@once
{{-- `hidden` alone does not hide it: Tailwind's preflight rule for the attribute
     is `[hidden]:where(:not([hidden="until-found"]))`, whose `:where()` counts for
     nothing — so it ties with the `inline-flex` utility below on specificity and
     loses on order. Without this the pill is painted from first render, at the
     static position a `fixed` element falls back to, which is the table's corner.
     Scoped to the pill's own attribute so it beats the utility (0,2,0) without
     touching anything else on the page. --}}
<style>[data-copy-feedback][hidden] { display: none; }</style>
<span
    data-copy-feedback
    hidden
    aria-live="polite"
    class="pointer-events-none fixed z-50 -translate-y-1/2 inline-flex items-center gap-1 rounded-sm bg-white/90 dark:bg-gray-800/90 px-1.5 py-0.5 shadow-sm text-xs font-medium {{ Palette::getModalIconTextClass('success') }}"
>{!! icon('check', 'w-4 h-4', Palette::getModalIconTextClass('success')) !!}<span data-copy-feedback-text></span></span>
@endonce
