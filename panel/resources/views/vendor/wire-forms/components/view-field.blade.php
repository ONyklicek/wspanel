@php /** @var \NyonCode\WireForms\Components\Display\ViewField $field */ @endphp

<div class="wire-field" @wireExtraAttributes($field)>
    @if($field->getLabel())
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            {{ $field->getLabel() }}
        </label>
    @endif

    @if($field->getView())
        @include($field->getView(), $field->getViewData())
    @elseif($field->getContent())
        @if($field->isHtmlContent())
            {{-- WARNING: Raw HTML — only use with trusted content. --}}
            {!! $field->getContent() !!}
        @else
            <div class="text-sm text-gray-900 dark:text-white">{{ $field->getContent() }}</div>
        @endif
    @endif

    @if($field->getHelperText())
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $field->getHelperText() }}</p>
    @endif
</div>
