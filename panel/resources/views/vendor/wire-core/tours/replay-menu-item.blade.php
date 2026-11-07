{{-- "Show me that again", in the signed-in person's own menu.

     Rendered as an object rather than as `<x-wire::menu-item>`: this is an
     engine package, and Rendering Rule 5 keeps `<x-*>` out of its render paths.
     `ComponentRenderer::render()` is the sanctioned way to reach the same
     canonical component — same class, same single view, the slot and the
     attributes passed explicitly — so the row looks like every other row in the
     menu without the tag.

     The wrapper is not decoration. A Livewire component must always render one
     root element, and this one renders nothing on a screen no tour claims; the
     div is that root. It carries no classes, and the item inside is `w-full`, so
     it changes nothing about the menu when the item is there. --}}
<div x-data x-on:wire-tour:forgotten="window.location.reload()">
    @if ($tourId !== '')
        {!! \NyonCode\WireCore\Foundation\View\ComponentRenderer::render(
            new \NyonCode\WireCore\Foundation\View\MenuItem(icon: 'outline:academic-cap'),
            __('wire-core::messages.tour_replay'),
            ['wire:click' => 'replay', 'data-testid' => 'tour-replay'],
        ) !!}
    @endif
</div>
