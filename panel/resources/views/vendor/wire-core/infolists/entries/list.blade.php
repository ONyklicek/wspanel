@php
    use NyonCode\WireCore\Infolists\Components\ListEntry;

    assert($field instanceof ListEntry);

    $spanClass = $field->getColumnSpanClass();
    $items = $field->getVisibleItems();
    $remaining = $field->getRemainingCount();
    $isBadge = $field->isBadge();
    $badgeClass = $field->getBadgeColorClass();
    $textColor = $field->getTextColorClass();
@endphp

<div class="{{ $spanClass }}" @wireExtraAttributes($field)>
    @if($field->hasVisibleLabel())
        @include('wire-core::partials.entry-label', ['text' => $field->getLabel()])
    @endif

    <div class="text-sm">
        @if(count($items))
            @if($isBadge)
                <div class="flex flex-wrap items-center gap-1.5">
                    @foreach($items as $item)
                        <span @class([
                            'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium',
                            $badgeClass,
                        ])>
                            @if($field->getIcon())
                                {!! icon($field->getIcon(), 'w-4 h-4', 'w-3.5 h-3.5') !!}
                            @endif
                            {{ $item }}
                        </span>
                    @endforeach
                    @if($remaining > 0)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                            +{{ $remaining }}
                        </span>
                    @endif
                </div>
            @else
                <ul @class(['space-y-0.5', 'list-disc list-inside' => $field->isBulleted()])>
                    @foreach($items as $item)
                        <li class="{{ $textColor }}">{{ $item }}</li>
                    @endforeach
                    @if($remaining > 0)
                        <li class="text-gray-500 dark:text-gray-400">+{{ $remaining }} {{ __('more') }}</li>
                    @endif
                </ul>
            @endif
        @else
            <span class="text-gray-500 dark:text-gray-400">{{ $field->getPlaceholder() ?? '-' }}</span>
        @endif
    </div>

    @if($field->hasActions())@include('wire-core::infolists.entry-actions')@endif
</div>
