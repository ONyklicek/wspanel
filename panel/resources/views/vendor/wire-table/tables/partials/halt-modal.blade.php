{{-- The table's halt modal: core owns the rendering, the table owns the paths. --}}
@if($component->tableState->get('modal.halt.show'))
    @include('wire-core::actions.partials.halt-modal', [
        'config' => $component->getHaltModalData(),
        'formInstance' => $component->getHaltModalFormInstance(),
        'showModel' => 'tableState.modal.halt.show',
        'submitAction' => 'submitHaltModal',
        'closeAction' => 'closeHaltModal',
    ])
@endif
