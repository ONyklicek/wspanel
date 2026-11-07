{{--
    The "⋯" a finger opens the row's menu with — see Table::getTouchMenuActions().
    Only a coarse pointer shows it: on a mouse the same actions are a double click, a
    right click or a key away. The wireRecordActions controller on the <tbody> answers
    its click by opening the row's menu under it. Tags touch on purpose: this is
    emitted once per row.
--}}
<button type="button" data-touch-menu-trigger data-testid="row-touch-menu" aria-haspopup="menu" aria-label="{{ __('wire-table::messages.row_touch_menu') }}" title="{{ __('wire-table::messages.row_touch_menu') }}" class="hidden [@media(pointer:coarse)]:inline-flex items-center justify-center rounded-md p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200">{!! icon('outline:ellipsis-horizontal', 'w-5 h-5') !!}</button>
