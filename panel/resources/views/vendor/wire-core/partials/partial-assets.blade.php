{{-- The client half of `wire:partial`.

     A partial anchor is only an attribute; something has to read
     `effects.wirePartials` off the response and morph the markup into it. That
     applier is `resources/js/support/partials.js`, which ships inside
     `wire-core-dropdown.js` — the bundle that carries the whole shared
     interaction layer.

     Include this from any surface that emits a `wire:partial` anchor. Without it
     the anchor is inert: the response still carries the region, the browser
     still receives it, and nothing on the page changes — no error, no warning.
     A widget dashboard is the case that proved it, having no floating surface to
     pull the bundle in the way a table's dropdowns and editable cells do.

     `@assets` rather than `@push`: no package layout renders a matching
     `@stack('scripts')`, and a DOM-morphed <script> inside a Livewire-loaded
     modal never executes. Livewire dedupes it per request, so this and
     `floating-assets` together still emit one tag. --}}
@assets
@packageScripts('wire-core', 'wire-core-dropdown.js')
@endassets
