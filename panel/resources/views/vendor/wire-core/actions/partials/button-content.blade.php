{{-- Button icon + label content. Used by action button views. --}}
@if($data['hideLabel'] ?? false)
    {!! $data['iconHtml'] ?? '' !!}
@elseif(($data['iconPosition'] ?? 'before') === 'after')
    <span>{{ $data['label'] ?? '' }}</span>{!! $data['iconHtml'] ?? '' !!}
@else
    {!! $data['iconHtml'] ?? '' !!}<span>{{ $data['label'] ?? '' }}</span>
@endif
@if(($data['shortcutLabel'] ?? null) && !($data['hideLabel'] ?? false))
    <kbd class="hidden sm:inline-block ml-1 px-1 py-0.5 text-[10px] font-mono bg-white/20 rounded-sm opacity-80">{{ $data['shortcutLabel'] }}</kbd>
@endif
