{{-- The invisible half of a tour: the round trips it makes — how far somebody
     got, each time a step is shown, and that they are done with it.

     Separate from the chrome beside it, and not merely for tidiness. The chrome
     is wrapped in `wire:ignore` so a morph cannot strip the inline positioning
     Floating UI wrote onto the panel — and a component cannot both be ignored by
     Livewire and call it. Splitting them lets each have what it needs: the
     chrome keeps its styles, and this keeps its round trip.

     It also means the walkthrough itself runs identically whether or not
     anything is listening. Without this element the tour still opens, steps and
     closes; it just forgets afterwards.

     The event is claimed by id. Only one tour can be on a page at a time, so
     this is belt-and-braces rather than a real ambiguity — but the server reads
     the id from its own property regardless, so a forged event can acknowledge
     only the tour that was already rendered here. --}}
<div
    x-data
    x-on:wire-tour:reached.window="$event.detail.id === @js($tourId) && $wire.reach($event.detail.step)"
    x-on:wire-tour:done.window="$event.detail.id === @js($tourId) && $wire.acknowledge()"
    x-on:wire-tour:postponed.window="$event.detail.id === @js($tourId) && $wire.postpone()"
></div>
