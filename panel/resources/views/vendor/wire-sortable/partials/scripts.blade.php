@php
    // Cache-bust by the bundle's mtime so a rebuild is picked up without a manual
    // version bump; falls back to no query string if the file is not present yet.
    $assetFile = \NyonCode\WireSortable\WireSortableServiceProvider::ASSETS_PATH.'/wire-sortable.js';
    $assetVersion = is_file($assetFile) ? (string) filemtime($assetFile) : null;
    $assetUrl = route('wire-sortable.asset', ['asset' => 'sortable']).($assetVersion ? '?id='.$assetVersion : '');

    // Backwards compatibility: SortableJS now ships inside the bundle above, so
    // this is null by default. An app that still sets it gets its CDN copy as
    // well — the controller uses the bundled import either way.
    $cdnUrl = config('wire-sortable.sortablejs_cdn');
@endphp

{{-- Pre-bundled drag controller (wireSortable) with SortableJS compiled in.
     Loaded through Livewire's @assets directive so it is emitted once per page,
     lands in the document head ahead of any x-data that references the factory,
     and is re-injected after a Livewire navigation — a plain <script> tag in the
     body would be DOM-morphed and never execute. The bundle registers itself
     unconditionally (not only on alpine:init), so it also works when it arrives
     after Alpine has already started. --}}
@assets
@if($cdnUrl)
<script src="{{ $cdnUrl }}"></script>
@endif
<script src="{{ $assetUrl }}"></script>

{{-- The .wire-sortable-* classes stay inline rather than moving to a CSS asset:
     Tailwind's scanner never sees them (they are applied from JS, on elements
     JS creates), and @assets already emits this block once per page, so a
     second delivery channel — another route, another request — would buy
     nothing. --}}
<style>
    /* ── Drag Handle ─────────────────────────────────── */

    .wire-sortable-th {
        width: 2.5rem;
        padding: 0.75rem 0 0.75rem 0.75rem;
    }

    .wire-sortable-handle-cell {
        width: 2.5rem;
        padding: 0.75rem 0 0.75rem 0.75rem;
        vertical-align: middle;
    }

    .wire-sortable-handle {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 1.75rem;
        height: 1.75rem;
        border-radius: 0.375rem;
        cursor: grab;
        color: rgb(107 114 128);
        transition: color 150ms ease, background-color 150ms ease;
        user-select: none;
        -webkit-user-select: none;
    }

    .wire-sortable-handle:hover {
        color: rgb(55 65 81);
        background-color: rgb(243 244 246);
    }

    .wire-sortable-handle:active {
        cursor: grabbing;
        color: rgb(79 70 229);
        background-color: rgb(238 242 255);
    }

    .dark .wire-sortable-handle {
        color: rgb(107 114 128);
    }

    .dark .wire-sortable-handle:hover {
        color: rgb(209 213 219);
        background-color: rgb(55 65 81);
    }

    .dark .wire-sortable-handle:active {
        color: rgb(129 140 248);
        background-color: rgb(49 46 129 / 0.4);
    }

    /* ── Row Ghost (placeholder left behind) ─────────── */

    .wire-sortable-ghost {
        background-color: rgb(243 244 246);
    }

    .wire-sortable-ghost > td {
        opacity: 0;
    }

    .dark .wire-sortable-ghost {
        background-color: rgb(55 65 81);
    }

    /* ── Row Chosen (before drag starts moving) ──────── */

    .wire-sortable-chosen {
        background-color: inherit;
    }

    /* ── Row Drag / Fallback Clone ────────────────────── */

    .wire-sortable-drag,
    .wire-sortable-fallback {
        background-color: white !important;
        box-shadow:
            0 10px 30px -5px rgb(0 0 0 / 0.12),
            0 4px 12px -2px rgb(0 0 0 / 0.08),
            0 0 0 1px rgb(0 0 0 / 0.04) !important;
        border-radius: 0.5rem !important;
        opacity: 1 !important;
        z-index: 9999 !important;
    }

    .dark .wire-sortable-drag,
    .dark .wire-sortable-fallback {
        background-color: rgb(31 41 55) !important;
        box-shadow:
            0 10px 30px -5px rgb(0 0 0 / 0.4),
            0 4px 12px -2px rgb(0 0 0 / 0.3),
            0 0 0 1px rgb(255 255 255 / 0.05) !important;
    }

    /* ── Column Ghost ────────────────────────────────── */

    .wire-sortable-column-ghost {
        opacity: 0.3;
        background-color: rgb(238 242 255);
    }

    .dark .wire-sortable-column-ghost {
        background-color: rgb(49 46 129 / 0.3);
    }

    .wire-sortable-column-chosen {
        background-color: inherit;
    }

    .wire-sortable-column-drag,
    .wire-sortable-column-fallback {
        background-color: rgb(249 250 251) !important;
        box-shadow: 0 4px 12px -2px rgb(0 0 0 / 0.1) !important;
        border-radius: 0.375rem !important;
        opacity: 1 !important;
    }

    .dark .wire-sortable-column-drag,
    .dark .wire-sortable-column-fallback {
        background-color: rgb(31 41 55) !important;
    }

    [data-sortable-column] {
        transition: background-color 150ms ease;
    }

    [data-sortable-column]:active {
        cursor: grabbing;
    }

    /* ── Global: disable text selection during drag ──── */

    .wire-sortable-active {
        user-select: none;
        -webkit-user-select: none;
        cursor: grabbing !important;
    }

    .wire-sortable-active * {
        cursor: grabbing !important;
    }
</style>
@endassets
