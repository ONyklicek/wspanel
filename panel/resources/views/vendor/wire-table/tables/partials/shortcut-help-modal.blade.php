{{-- The `?` keyboard-shortcut help.

     Opened purely client-side: the grid controller dispatches the window event
     named in its keyboard config, and the modal shell listens for it (openOn).
     No Livewire roundtrip, so the help costs nothing until it is asked for, and
     the event name carries the component id so a page with several tables opens
     only the one whose row has focus. --}}
@if($shortcutHelpEvent !== null)
    {{-- Rule 5: rendered as a Htmlable object, not the <x-*> component. --}}
    {{ new \NyonCode\WireCore\Modals\Html\Modal(
        heading: __('wire-table::messages.shortcuts_heading'),
        description: __('wire-table::messages.shortcuts_description'),
        width: 'lg',
        icon: 'outline:command-line',
        openOn: $shortcutHelpEvent,
        {{-- The id also keys the teleport: a form action renders the same Modal
             shell in this very component, and Livewire morphs by wire:key. --}}
        id: $shortcutHelpEvent,
        bodyView: 'wire-table::tables.partials.shortcut-help',
        bodyData: ['sections' => $shortcutLegend->sections()],
    ) }}
@endif
