{{-- Pre-bundled chart controller (wireChart). Loaded through Livewire's @assets
     directive — never @push, which needs a @stack('scripts') no package layout
     renders — so the script is emitted once per page and also runs when the
     widget renders inside a Livewire-loaded modal, where a DOM-morphed <script>
     tag would never execute.

     No longer per-surface delivery: this bundle is 671 bytes of Alpine registrar,
     not the heavy optional body it was once filed as, and delivering a registrar
     late is the one thing ADR 0024 forbids. It now ships with the rest of the
     stack, so an app rendering @wireStackScripts already has it and this partial
     dedupes to a no-op. It stays for the app that renders no such tag at all.

     Chart.js itself is the consuming app's dependency and is not shipped here. --}}
@assets
@packageScripts('wire-core', 'wire-core-chart.js')
@endassets
