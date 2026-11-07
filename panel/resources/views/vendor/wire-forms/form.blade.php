{{-- A field anchor is only an attribute; the code that reads
     `effects.wirePartials` and morphs each region into it lives in wire-core's
     dropdown bundle. A form of plain inputs has no dropdown, modal or table to
     pull that bundle in, so without this the server would answer with regions
     and nothing on the page would change — no error, no warning. Only where the
     form asked for field partials, so an ordinary form ships exactly what it
     always did. --}}
@if($fieldPartials ?? false)
    @include('wire-core::partials.partial-assets')
@endif

<div class="wire-forms-form space-y-6">
    @foreach($components as $component)
        @if($component->isVisible())
            {{ $component }}
        @endif
    @endforeach
</div>
