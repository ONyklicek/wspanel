{{-- The halt modal, for any host that runs actions.

     A halt is raised inside the action pipeline, which is core's, so the modal
     it shows is core's too: the table and the standalone WithActions host pass
     their own state paths and share this one rendering. Before 2.0 only the
     table had a view for it, so a halt outside a table set state that nothing
     drew — the action stopped and the screen said nothing.

     Expects: $config (the halt's serialized modal bag), $formInstance
     (Htmlable|null), $showModel, $submitAction, $closeAction. --}}
@php
    // A form is drawn only when the halt still declares one: informative()
    // clears it, and hasForm() is what says so.
    $haltFormBody = ($config['hasForm'] ?? false) ? ($formInstance ?? null) : null;
@endphp

{{-- Rule 5: rendered as a Htmlable object, not the <x-*> component. --}}
{{ new \NyonCode\WireCore\Modals\Html\Confirmation(
    heading: $config['heading'] ?? null,
    description: $config['description'] ?? null,
    width: $config['width'] ?? 'md',
    icon: $config['icon'] ?? null,
    iconColor: $config['iconColor'] ?? 'warning',
    submitLabel: $config['submitLabel'] ?? __('wire-core::actions.confirm_submit'),
    cancelLabel: $config['cancelLabel'] ?? __('wire-core::actions.confirm_cancel'),
    color: $config['color'] ?? null,
    isDanger: $config['danger'] ?? false,
    isInformative: $config['informative'] ?? false,
    closeOnClickAway: $config['closeOnClickAway'] ?? true,
    closeOnEscape: $config['closeOnEscape'] ?? true,
    maxHeight: $config['maxHeight'] ?? null,
    id: $config['id'] ?? null,
    closeAction: $closeAction,
    wireModel: $showModel,
    wireClick: $submitAction,
    body: $haltFormBody,
) }}
