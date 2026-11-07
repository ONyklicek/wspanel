@php
    use NyonCode\WireCore\Infolists\Components\TextEntry;

    assert($field instanceof TextEntry);

    // The canonical owner, not a sixth copy of its `match`: HasColumnSpan
    // exists so a span means the same thing on every grid surface, and five
    // views had quietly kept their own.
    $spanClass = $field->getColumnSpanClass();
    $textColor = $field->getTextColorClass();
@endphp

<div class="{{ $spanClass }}" @wireExtraAttributes($field)>
    @if($field->hasVisibleLabel())
        @include('wire-core::partials.entry-label', ['text' => $field->getLabel()])
    @endif

    <div @class(['text-sm', $field->getWeightClass()])
         @if($field->getTooltip()) title="{{ $field->getTooltip() }}" @endif
    >
        @if($field->isList())
            <ul @class(['space-y-0.5', 'list-disc list-inside' => $field->isBulleted()])>
                @foreach($field->getFormattedStates() as $item)
                    <li class="{{ $textColor }}">{{ $item }}</li>
                @endforeach
            </ul>
        @elseif($field->isBadge())
            <span @class([
                'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium',
                $field->getBadgeColorClass(),
            ])>
                @if($field->getIcon())
                    {!! icon($field->getIcon(), 'w-4 h-4', 'w-3.5 h-3.5') !!}
                @endif
                {{ $field->getFormattedState() }}
            </span>
        @else
            {{-- The copy affordance is the canonical one: a plain button that the
                 document-wide listener in the `copy` bundle answers, not an Alpine
                 component with its own `copied` flag and timeout per entry. Same
                 markup a table's copyable cell uses — see Foundation\View\CopyButton. --}}
            <span
                    @class([
                        'inline-flex items-center gap-1 group',
                        $field->isProse() ? 'prose dark:prose-invert max-w-none' : $textColor,
                    ])
            >
                @if($field->getIcon())
                    {!! icon($field->getIcon(), 'w-4 h-4', 'w-4 h-4 text-gray-400') !!}
                @endif
                {{ $field->getFormattedState() }}
                @if($field->isCopyable())
                    {!! app(\NyonCode\WireCore\Foundation\View\CopyButton::class)->render(
                        value: (string) $field->getFormattedState(),
                        message: __('wire-core::messages.copied'),
                        testId: 'entry-copy',
                    ) !!}
                @endif
            </span>
        @endif
    </div>

    @if($field->isCopyable())@include('wire-core::partials.copy-assets')@endif
    @if($field->hasActions())@include('wire-core::infolists.entry-actions')@endif
</div>
