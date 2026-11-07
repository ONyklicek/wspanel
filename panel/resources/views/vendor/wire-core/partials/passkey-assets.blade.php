{{-- The passkey controller (`wirePasskey`), included by any surface that draws a
     passkey control — the auth module's sign-in button, the users module's
     profile card.

     Included per surface rather than declared as an entry, for the reason the
     sortable-list partial gives: a declared entry is rendered into the <head> of
     every page by `@wireStackScripts`, and this bundle compiles Laravel's own
     `@laravel/passkeys` client in. Twelve kilobytes on every page of every
     application, for a feature most of them have switched off, is exactly the
     cost `Bundle::serve()`'s route exists to avoid.

     `@assets` rather than `@push`: no package layout renders a matching
     `@stack('scripts')`, and a DOM-morphed <script> inside a Livewire-loaded
     modal never executes. Livewire dedupes it per request, so a page drawing both
     the button and the card still costs one tag. --}}
@php
    // Cache-bust by the bundle's mtime so a rebuild is picked up without a manual
    // version bump; no query string when the file has not been built yet.
    $passkeyFile = \NyonCode\WireCore\WireCoreServiceProvider::ASSETS_PATH.'/wire-core-passkey.js';
    $passkeyUrl = route('wire-core.asset', ['asset' => 'passkey'])
        .(is_file($passkeyFile) ? '?id='.filemtime($passkeyFile) : '');
@endphp

@assets
<script src="{{ $passkeyUrl }}" data-navigate-once></script>
@endassets
