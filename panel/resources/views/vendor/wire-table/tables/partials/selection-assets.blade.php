@php
    // The tag itself belongs to the toolkit's renderer (`@packageScripts` below),
    // which owns delivery and the attributes the declaration carries. The path is
    // still needed here to choose the branch, and to read the source for the
    // fallback when the compiled bundle is absent.
    $selectionAssetFile = \NyonCode\WireTable\WireTableServiceProvider::ASSETS_PATH.'/wire-table-selection.js';
@endphp

{{-- Pre-bundled selection component (wireRecordSelection). Loaded through
     Livewire's @assets directive so the script registers once and also runs when
     the table renders inside a Livewire-loaded modal, where a DOM-morphed
     <script> tag would never execute. --}}
@assets
@if(is_file($selectionAssetFile))
@packageScripts('wire-table', 'wire-table-selection.js')
@else
{{-- The x-data on the table wrapper references the factory either way, and the
     wrapper owns search, filters, the bulk bar, pagination, the mobile cards
     and the modal hosts — a dangling reference would kill Alpine for all of it,
     silently. The source is import-free on purpose so it can stand in verbatim
     when the compiled bundle is missing.

     Wrapped in an IIFE, which is exactly what esbuild does to the same source
     for the bundle above (`--format=iife`): inlined bare, its top-level `let`
     declarations land in the document's GLOBAL LEXICAL SCOPE, so a second copy
     in one document would be a SyntaxError that takes the whole script with it
     — and `var` instead would leak names as generic as `registered` onto
     window. Livewire's @assets dedupes per request and its head merge skips an
     identical tag, so a second copy should not happen; this makes "should not"
     stop mattering, and makes the fallback behave like the bundle. --}}
<script>(function () {
{!! file_get_contents(dirname($selectionAssetFile, 2).'/resources/js/record-selection.js') !!}
})();</script>
@endif
@endassets
