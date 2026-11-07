@php
    use NyonCode\WireCore\Infolists\Components\ColorEntry;

    assert($field instanceof ColorEntry);

    $spanClass = $field->getColumnSpanClass();
    $value = $field->getFormattedState();
    $swatch = $field->getSwatch();
@endphp

<div class="{{ $spanClass }}" @wireExtraAttributes($field)>
    @if($field->hasVisibleLabel())
        @include('wire-core::partials.entry-label', ['text' => $field->getLabel()])
    @endif

    <div class="text-sm">
        @if($value !== '-' && $value !== '')
            {{-- Same canonical copy affordance the text entry and a table's copyable
                 cell use: one button, one document listener, no Alpine per entry. --}}
            <span class="inline-flex items-center gap-2 group">
                @if($swatch !== null)
                    <span class="w-5 h-5 rounded-sm ring-1 ring-gray-200 dark:ring-gray-700"
                          @style(['background-color: '.$swatch])></span>
                @endif
                <span class="font-mono text-gray-700 dark:text-gray-300">{{ $value }}</span>
                @if($field->isCopyable())
                    {!! app(\NyonCode\WireCore\Foundation\View\CopyButton::class)->render(
                        value: (string) $value,
                        message: __('wire-core::messages.copied'),
                        testId: 'entry-copy',
                    ) !!}
                @endif
            </span>
        @else
            <span class="text-gray-500 dark:text-gray-400">{{ $field->getPlaceholder() ?? '-' }}</span>
        @endif
    </div>

    @if($field->isCopyable())@include('wire-core::partials.copy-assets')@endif
    @if($field->hasActions())@include('wire-core::infolists.entry-actions')@endif
</div>
