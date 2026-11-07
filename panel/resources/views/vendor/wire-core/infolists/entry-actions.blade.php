{{-- Inline action buttons rendered below an infolist entry value.
     Expects: $field (an Entry using HasActions). --}}
@if($field->hasActions())
    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
        @foreach($field->getActions() as $entryAction)
            @include('wire-core::partials.component-action', ['action' => $entryAction])
        @endforeach
    </div>
@endif
