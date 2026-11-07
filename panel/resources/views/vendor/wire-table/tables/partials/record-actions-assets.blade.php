{{-- Pre-bundled record-action controller (wireRecordActions). Loaded through
     Livewire's @assets directive so the script registers once and also runs when
     the table renders inside a Livewire-loaded modal, where a DOM-morphed
     <script> tag would never execute.

     The tag is the toolkit renderer's, which owns delivery and the attributes the
     declaration carries; building one here would be a second resolver for the same
     concern. --}}
@assets
@packageScripts('wire-table', 'wire-table-records.js')
@endassets
