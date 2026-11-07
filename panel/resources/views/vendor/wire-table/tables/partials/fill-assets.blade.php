{{-- Pre-bundled fill handle (wireFillHandle). Loaded through Livewire's @assets
     directive so the script registers once and also runs when the table renders
     inside a Livewire-loaded modal, where a DOM-morphed <script> tag would never
     execute.

     Its own partial since ADR 0025 § step 10. The handle used to arrive inside
     `wire-core-dropdown.js`, so this view included `wire-core::partials
     .floating-assets` and got it for free — which is also why every wire-core
     consumer paid 9 KB for a gesture only a table can trigger. There is no
     inline-source fallback branch here (unlike selection-assets): the entry
     imports three modules, so the raw file cannot stand in for the bundle.

     The tag is the toolkit renderer's, which owns delivery and the attributes the
     declaration carries; building one here would be a second resolver for the same
     concern. --}}
@assets
@packageScripts('wire-table', 'wire-table-fill.js')
@endassets
