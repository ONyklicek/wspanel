<span
    @wireEl('badge') {{ $attributes->merge(['class' => "inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium {$colorClasses}"]) }}>
    @if($icon)
        {!! icon($icon, 'w-3 h-3') !!}
    @endif
    {{ $slot }}
</span>
