{{-- The halt, on any component that can raise one.

     A halt outlives whatever raised it — an action's modal, or a plain method
     call on a component that has no actions at all — so it is drawn from its own
     state rather than from inside an action's frame. The action modal host
     includes this view, which is why a host that already renders that one needs
     nothing else.

     Expects: $component. Optional: $showModel, $submitAction, $closeAction. --}}
@if(method_exists($component, 'isHaltModalVisible') && $component->isHaltModalVisible())
    @include('wire-core::actions.partials.halt-modal', [
        'config' => $component->getHaltModalData(),
        // The fields are wire-forms'. A core-only host has none, and a halt
        // there is a confirmation — heading, description, two buttons.
        'formInstance' => method_exists($component, 'getHaltModalFormInstance')
            ? $component->getHaltModalFormInstance()
            : null,
        'showModel' => $showModel ?? 'mountedHalt.show',
        'submitAction' => $submitAction ?? 'submitHaltModal',
        'closeAction' => $closeAction ?? 'closeHaltModal',
    ])
@endif
