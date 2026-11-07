@php
    // The tag itself belongs to the toolkit's renderer (`@packageScripts` below),
    // which owns delivery and the attributes the declaration carries. The path is
    // still needed here to choose the branch, and to read the source for the
    // fallback when the compiled bundle is absent.
    $liveAssetFile = \NyonCode\WireTable\WireTableServiceProvider::ASSETS_PATH.'/wire-table-live.js';
@endphp

{{-- Pre-bundled live-broadcast bridge (wireTableLive). Included only by a table
     that asked for live(broadcast: true), so a table without the opt-in carries
     neither the bundle nor a channel to authorize.

     Through Livewire's @assets for the same reason as the selection bundle: the
     script has to register once, and has to run when the table renders inside a
     Livewire-loaded modal, where a DOM-morphed <script> would never execute. --}}
@assets
@if(is_file($liveAssetFile))
@packageScripts('wire-table', 'wire-table-live.js')
@else
{{-- The x-data on the polling wrapper references the factory either way, and that
     wrapper contains the entire table — a dangling reference would take Alpine
     down for all of it, silently. The source is import-free on purpose so it can
     stand in verbatim, and is wrapped in an IIFE because that is what esbuild
     does to the same file for the bundle above: inlined bare, its top-level
     declarations would land in the document's global lexical scope. --}}
<script>(function () {
{!! file_get_contents(dirname($liveAssetFile, 2).'/resources/js/record-live.js') !!}
})();</script>
@endif
@endassets
