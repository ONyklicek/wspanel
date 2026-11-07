{{-- Group header with a collapse toggle — Table::collapsibleGroups().

     A file of its own rather than an @@if inside group-header.blade.php: that
     partial is compiled into a one-slot skeleton whose per-group byte cost
     TablePayloadFuseTest measures, and adding a branch to it moved that number
     even though the branch never rendered. Two shapes, two templates, and the
     table without collapsing pays exactly what it paid before.

     Variables: $colSpan, $cellPadding, $isCollapsed. Slots: $label (escaped
     text), $group (escaped for an attribute), $groupJs (encoded for the click).

     Tags touch: a whitespace run between two tags is one text node the morph
     walks, and this is emitted once per group. --}}
<tr class="bg-gray-100/80 dark:bg-gray-800/80 border-t border-gray-200 dark:border-gray-700" data-group="{{ $group }}"><td colspan="{{ $colSpan }}" class="{{ $cellPadding }} py-2"><button type="button" wire:click="toggleGroup({{ $groupJs }})" wire:loading.attr="disabled" wire:target="toggleGroup({{ $groupJs }})" aria-expanded="{{ $isCollapsed ? 'false' : 'true' }}" data-testid="group-toggle" @wireEl('group-toggle') class="flex w-full items-center gap-2 text-left"><span class="text-gray-400 transition-transform {{ $isCollapsed ? '-rotate-90' : '' }}">{!! icon('outline:chevron-down', 'h-4 w-4') !!}</span><span class="text-sm font-semibold text-gray-900 dark:text-white">{!! $label !!}</span></button></td></tr>
