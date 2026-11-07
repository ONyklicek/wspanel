@php
    use NyonCode\WireCore\Infolists\Components\KeyValueEntry;

    assert($field instanceof KeyValueEntry);

    $spanClass = $field->getColumnSpanClass('col-span-full');
    $pairs = $field->getPairs();
@endphp

<div class="{{ $spanClass }}" @wireExtraAttributes($field)>
    @if($field->hasVisibleLabel())
        @include('wire-core::partials.entry-label', ['text' => $field->getLabel()])
    @endif

    <div class="text-sm">
        @if(count($pairs))
            <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-left">
                    <thead class="bg-gray-50 dark:bg-gray-800/50">
                    <tr>
                        <th class="px-3 py-2 text-xs font-medium text-gray-500 dark:text-gray-400">{{ $field->getKeyLabel() }}</th>
                        <th class="px-3 py-2 text-xs font-medium text-gray-500 dark:text-gray-400">{{ $field->getValueLabel() }}</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($pairs as $key => $value)
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-700 dark:text-gray-300">{{ $key }}</td>
                            <td class="px-3 py-2 text-gray-900 dark:text-white">{{ is_scalar($value) ? $value : json_encode($value) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <span class="text-gray-500 dark:text-gray-400">{{ $field->getPlaceholder() ?? '-' }}</span>
        @endif
    </div>

    @if($field->hasActions())@include('wire-core::infolists.entry-actions')@endif
</div>
