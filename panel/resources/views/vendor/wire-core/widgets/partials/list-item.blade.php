{{-- The inside of one ListWidget entry, shared by the linked and unlinked arms
     of the row so the two cannot drift.

     Variables: $item --}}
@if($item->getIcon())
    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ $item->getIconBackgroundClass() }}">
        {!! icon($item->getIcon(), 'w-4 h-4', 'h-4 w-4 '.$item->getIconColorClass()) !!}
    </span>
@endif

<span class="min-w-0 flex-1">
    <span class="block truncate text-sm font-medium text-gray-900 dark:text-white">{{ $item->getTitle() }}</span>
    @if($item->getDescription())
        <span class="mt-0.5 block truncate text-sm text-gray-500 dark:text-gray-400">{{ $item->getDescription() }}</span>
    @endif
</span>

@if($item->getMeta())
    <span class="shrink-0 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">{{ $item->getMeta() }}</span>
@endif
