{{-- The wire-forms field controllers (date/time pickers, tags, rating, the rich
     and markdown editors, the colour picker, the OTP boxes, the phone input and
     the signature pad), registered as Alpine.data() factories.

     Every field whose body moved out of its `x-data` includes this, because
     `@wireStackScripts` is additive rather than required: an app that never adds
     the directive to its layout still has to get the controller, or its
     `x-data="wireDateTimePicker(…)"` evaluates against an empty registry and the
     field silently does nothing. That is the same contract every other
     per-surface asset partial keeps (architecture/assets.md § The directive), and
     it is what the workbench previews run on.

     `@assets` rather than `@push`: no package layout renders a matching
     `@stack('scripts')`, and a DOM-morphed <script> inside a Livewire-loaded
     modal never executes. Livewire dedupes it per request, so including it from
     six field views costs one tag — and the bundle's own `registered` guard makes
     a second execution a no-op where the layout emits it too. --}}
@assets
@packageScripts('wire-forms', 'wire-forms-fields.js')
@endassets
