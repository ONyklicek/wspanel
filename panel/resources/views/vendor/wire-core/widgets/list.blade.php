{{-- ListWidget — a short feed of entries.

     An entry with a url is an `<a>` and an entry without one is a `<div>`: that
     is a change of element, not of attributes, so it is written as a branch here
     rather than as a tag name computed in PHP. Both arms carry the same classes,
     which is the price of the branch and cheaper than the alternative.

     Variables: $widget, $items, $dividers, $filterOptions, $activeFilter, $filterExpression --}}
<div class="wire-list-widget rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
    @include('wire-core::widgets.partials.widget-header', [
        'widget' => $widget,
        'filterOptions' => $filterOptions,
        'activeFilter' => $activeFilter,
        'filterExpression' => $filterExpression,
    ])

    @if($items === [])
        <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ $widget->getEmptyState() }}</p>
    @else
        <ul @class(['wire-list-items', 'divide-y divide-gray-100 dark:divide-gray-700' => $dividers])>
            @foreach($items as $item)
                <li @foreach($item->getExtraAttributes() as $attr => $val) {{ $attr }}="{{ $val }}" @endforeach>
                    @if($item->getUrl())
                        <a href="{{ $item->getUrl() }}"
                           @if($item->opensInNewTab()) target="_blank" rel="noopener noreferrer" @else @wireNavigate($item->getUrl()) @endif
                           class="flex items-start gap-3 rounded-md py-3 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            @include('wire-core::widgets.partials.list-item', ['item' => $item])
                        </a>
                    @else
                        <div class="flex items-start gap-3 py-3">
                            @include('wire-core::widgets.partials.list-item', ['item' => $item])
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
