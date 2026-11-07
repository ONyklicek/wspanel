{{--
    The row's right-click menu panel — rendered ONCE per table, not once per row.

    The panel carries no per-row Alpine state: the single wireRecordActions controller
    on the <tbody> opens, positions and closes it by record key (data-record-menu). So
    the only things that vary per row are that key and the item markup, and they arrive
    as slots — $key inside an attribute, $menu as already-rendered markup.

    <template> is a script-supporting element, valid as a direct child of <tr>.

    $touch is the compiled record-touch-menu block (with its own slot) for a table
    that has touch-menu items, and an empty string for one that has none.

    See Table::getRowContextMenuSkeleton(). As everywhere in the row loop, the tags
    below deliberately touch: a run of whitespace between two tags is one DOM text node
    that the morph walks on every commit. Whitespace between attributes is free.
--}}
<template wire:key="ctx-{!! $key !!}" x-teleport="body"><div
        data-record-menu="{!! $key !!}"
        class="fixed z-50 min-w-[12rem] origin-top-left rounded-lg bg-white dark:bg-gray-800 shadow-lg ring-1 ring-black/5 dark:ring-white/10 focus:outline-none"
        style="display: none; left: 0; top: 0;"
        role="menu"><div class="py-1 empty:hidden">{!! $menu !!}</div>{!! $touch !!}</div></template>
