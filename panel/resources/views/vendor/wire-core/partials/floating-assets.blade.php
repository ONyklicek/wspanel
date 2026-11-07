{{-- Pre-bundled "Teleport + Floating UI" dropdown primitive.

     The tag comes from the toolkit's renderer, which owns delivery (mirror, then the
     package's own asset route) and puts `data-navigate-track` on it — writing the tag
     here would be a second resolver for the same concern, and would silently drop the
     attributes the declaration carries.

     Loaded through Livewire's @assets directive so the script registers once and
     also runs when the surface renders inside a Livewire-loaded modal (AJAX), where
     DOM-morphed <script> tags would never execute. --}}
@assets
@packageScripts('wire-core', 'wire-core-dropdown.js')
@endassets
