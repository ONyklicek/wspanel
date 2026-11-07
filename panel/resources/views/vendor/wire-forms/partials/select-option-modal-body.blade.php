{{-- Body of a Select create/edit option modal: the option form. Rendered via the
     Htmlable Modal object's bodyView (Rule 5). Expects $field, $form (the option
     form Htmlable), $mode ('create'|'edit') in scope (passed as bodyData). --}}
<div class="space-y-4" wire:key="{{ $mode }}-option-{{ $field->getId() }}">
    {{ $form }}
</div>
