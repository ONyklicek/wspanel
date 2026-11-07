@php
    // The tag belongs to the toolkit's renderer (`@packageScripts` below); the path
    // chooses the branch and is the fallback's source when the bundle is absent.
    $stickyAssetFile = \NyonCode\WireTable\WireTableServiceProvider::ASSETS_PATH.'/wire-table-sticky.js';
@endphp

{{-- The page-pinned header (wire-table-sticky.js). Included only by a table whose
     header follows the page (Table::hasPageStickyHeader()); a capped region pins
     its header with CSS `sticky` and needs none of this.

     Through @assets so it runs once per document, also when the table first
     arrives in a Livewire response. Without it the header simply scrolls away
     with the page — nothing references the script from markup.

     `scroll-margin-top` carries `--wire-sticky-top` — the height of any sticky
     chrome over the top of the page — to the script as a pixel value, and does
     the same job for anything that scrolls the header into view.

     The animation is the movement itself, against the window: the page's scroll
     drives it on the compositor, so the header moves in the same frame as the
     rows. The script only writes the range it runs over, and switches it on by
     `data-wire-sticky-timeline` once it has; without scroll-timeline support it
     never does, and translates the header itself. --}}
@assets
<style>
    [data-wire-sticky-head] {
        scroll-margin-top: var(--wire-sticky-top, 0px);
    }

    @keyframes wire-sticky-head {
        from { transform: translateY(0); }
        to { transform: translateY(var(--wire-sticky-travel, 0px)); }
    }

    @supports (animation-timeline: scroll()) {
        [data-wire-sticky-head][data-wire-sticky-timeline] {
            animation: wire-sticky-head linear both;
            animation-timeline: scroll(root block);
            animation-range: var(--wire-sticky-start, 0px) var(--wire-sticky-end, 0px);
        }
    }
</style>
@if(is_file($stickyAssetFile))
@packageScripts('wire-table', 'wire-table-sticky.js')
@else
<script>{!! file_get_contents(dirname($stickyAssetFile, 2).'/resources/js/record-sticky.js') !!}</script>
@endif
@endassets
