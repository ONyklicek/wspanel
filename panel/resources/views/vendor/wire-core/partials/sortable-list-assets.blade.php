{{-- The shared drag-to-reorder controller (`wireSortableList`), included by any
     surface whose items are ordered by position — a Repeater's cards, a Repeater
     table's rows, a Builder's blocks.

     Included per reorderable field rather than from a layout, for the reason
     every other per-surface asset partial exists: `@wireStackScripts` is
     additive, so an app that never added the directive would otherwise evaluate
     `x-data="wireSortableList(…)"` against an empty registry and the handle would
     do nothing — which is precisely the failure this controller was written to
     end. `@assets` dedupes it to one tag per request, and the bundle's own
     `registered` guard makes a second execution a no-op.

     Not `@packageScripts`: that renders a *declared* entry, and every declared
     entry is also rendered by `@wireStackScripts` — which would put this in the
     <head> of every page of every wire-core app. The bundle is served by
     `Bundle::serve('wire-core', …)`'s route instead, so it reaches only the
     pages that include this partial.

     The bundle is 2 kB now. It used to be 39 564 B, because it compiled
     SortableJS in — the same library Livewire 4 already ships inside its own
     Alpine Sort plugin, so a page with a repeater downloaded it twice. The
     controller borrows Livewire's copy through `x-sort:config`; see
     `wireSortableList`'s header for how. --}}
@php
    // Cache-bust by the bundle's mtime so a rebuild is picked up without a manual
    // version bump; no query string when the file has not been built yet.
    $sortableListFile = \NyonCode\WireCore\WireCoreServiceProvider::ASSETS_PATH.'/wire-core-sortable-list.js';
    $sortableListUrl = route('wire-core.asset', ['asset' => 'sortable-list'])
        .(is_file($sortableListFile) ? '?id='.filemtime($sortableListFile) : '');
@endphp

@assets
<script src="{{ $sortableListUrl }}" data-navigate-once></script>

{{-- The .wire-sortable-list-* classes stay inline, the same call
     `wire-sortable::partials.scripts` makes: Tailwind's scanner never sees them
     (they are applied from JS, to elements JS creates), and `@assets` already
     emits this block once per page. Deliberately distinct from
     `.wire-sortable-*` — a page can hold a reorderable table and a reorderable
     repeater, and a card should not inherit a table row's ghost. --}}
<style>
    /* ── Handle ──────────────────────────────────────── */

    .wire-sortable-list-active,
    .wire-sortable-list-active * {
        cursor: grabbing !important;
    }

    /* ── Placeholder left behind by the dragged item ──── */

    .wire-sortable-list-ghost {
        opacity: 0.4;
    }

    .wire-sortable-list-ghost > td {
        opacity: 0;
    }

    /* ── The element that follows the cursor ──────────── */

    .wire-sortable-list-drag,
    .wire-sortable-list-fallback {
        opacity: 1 !important;
        z-index: 9999 !important;
        border-radius: 0.5rem !important;
        background-color: white !important;
        box-shadow:
            0 10px 30px -5px rgb(0 0 0 / 0.12),
            0 4px 12px -2px rgb(0 0 0 / 0.08),
            0 0 0 1px rgb(0 0 0 / 0.04) !important;
    }

    .dark .wire-sortable-list-drag,
    .dark .wire-sortable-list-fallback {
        background-color: rgb(31 41 55) !important;
        box-shadow:
            0 10px 30px -5px rgb(0 0 0 / 0.4),
            0 4px 12px -2px rgb(0 0 0 / 0.3),
            0 0 0 1px rgb(255 255 255 / 0.05) !important;
    }
</style>
@endassets
