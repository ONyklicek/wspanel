{{-- The toolbar's reorder toggle, rendered once per table.

     It used to be a PHP string in WithSortable::getTableToolbarWidgets() —
     button, inline <svg> and both <path>s. The markup lives here now and the
     icon comes from the canonical owner via icon(), which is also what makes the
     two states one template instead of two hand-built SVGs. --}}
<button
    type="button"
    wire:click="toggleReordering"
    class="p-1.5 rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-primary-500 {{ $activeClass }}"
    title="{{ $title }}"
>{!! icon($icon, 'w-5 h-5') !!}</button>
