{{-- Shared infolist / panel entry label. Variables: $text, optional $margin (default mb-1). --}}
<div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 {{ $margin ?? 'mb-1' }}">
    {{ $text }}
</div>
