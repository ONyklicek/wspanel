@php /** @var \NyonCode\WireForms\Components\Display\Html $field */ @endphp

{{-- WARNING: Raw HTML output — content must be trusted. Never pass unsanitized user input. --}}
<div class="wire-field" @wireExtraAttributes($field)>
    {!! $field->getContent() !!}
</div>
