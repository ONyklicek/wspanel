{{--
    The items a row's menu adds when a finger opened it — Table::getTouchMenuActions():
    the behaviour-only record actions a mouse reaches by a double click, a right click
    or a key. Spliced into record-context-menu only for a table that has any, so a
    table without them pays nothing per row.

    display:none until the wireRecordActions controller opens the panel from a touch —
    an inline style for the reason the panel itself is one: the controller owns it,
    and a Tailwind variant for "opened by touch" would need a newer Tailwind than the
    shared views promise. The border shows only when right-click items precede it.
--}}
<div data-touch-menu class="py-1 [:not(:empty)+&]:border-t [:not(:empty)+&]:border-gray-100 dark:[:not(:empty)+&]:border-gray-700" style="display: none;">{!! $items !!}</div>
