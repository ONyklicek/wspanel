@php
    // The tag belongs to the toolkit's renderer (`@packageScripts` below); the path
    // chooses the branch and is the fallback's source when the bundle is absent.
    $viewportAssetFile = \NyonCode\WireTable\WireTableServiceProvider::ASSETS_PATH.'/wire-table-viewport.js';
@endphp

{{-- The viewport script (wire-table-viewport.js). Included only by a table that
     trims to the half the browser shows (Table::rendersVisibleLayoutOnly()), so a
     table that never stacks carries nothing.

     Through @assets so it runs once per document, also when the table first
     arrives in a Livewire response. Nothing references it from markup — it only
     writes the cookie and re-renders a table that holds the wrong half — so a page
     without it renders both halves for ever and loses nothing but the saving. --}}
@assets
@if(is_file($viewportAssetFile))
@packageScripts('wire-table', 'wire-table-viewport.js')
@else
<script>{!! file_get_contents(dirname($viewportAssetFile, 2).'/resources/js/record-viewport.js') !!}</script>
@endif
@endassets
