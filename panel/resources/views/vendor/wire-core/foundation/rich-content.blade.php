{{-- The content has already been through MentionRenderer: every mention in it
     carries the name and URL its record has right now, not the ones it had when
     somebody typed it. --}}
<div {{ $attributes->merge(['class' => trim('wire-rich-content '.($class ?? ''))]) }}>
    {!! $content !!}
</div>
