{{-- One widget's cell in the grid, and the region a poll tick, a filter change
     or a deferred load answers with.

     Rendered from two places — the grid loop, and `WithWidgets` — so the
     anchored element is the same markup either way. If it were not, a tick would
     replace the cell with something shaped differently and the morph would drop
     whatever the two disagreed on.

     The anchor sits INSIDE the wrapper that carries `wire:poll`, deliberately:
     Livewire stops a poll whose directive has left the element, so an anchor on
     the polling element itself would have to re-emit the directive on every tick
     to keep ticking. Keeping them separate means the tick's own markup cannot
     switch the tick off. The same holds for `wire:init`, which must not be
     re-emitted by the very response it triggered.

     Variables: $widget --}}
<div wire:partial="widget-{{ $widget->getKey() }}">
    @if($widget->isLazy())
        @include('wire-core::widgets.partials.widget-placeholder', ['widget' => $widget])
    @else
        {{ $widget }}
    @endif
</div>
