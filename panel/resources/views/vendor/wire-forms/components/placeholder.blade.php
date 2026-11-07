@php /** @var \NyonCode\WireForms\Components\Display\Placeholder $field */ @endphp

<div class="wire-field" @wireExtraAttributes($field)>
    @if($field->getLabel())
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            {{ $field->getLabel() }}
        </label>
    @endif

    <div class="text-sm text-gray-900 dark:text-white">
        @if($field->isHtmlContent())
            {{-- WARNING: Raw HTML — only use with trusted content (allowHtml/html). --}}
            {!! $field->getContent() !!}
        @else
            {{ $field->getContent() }}
        @endif
    </div>

    @if($field->getHelperText())
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $field->getHelperText() }}</p>
    @endif
</div>
