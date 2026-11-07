<div class="space-y-3">
    <nav class="flex flex-wrap items-center gap-1 text-sm" aria-label="Cesta">
        @if ($path !== '.')
            <x-wire::button size="sm" color="gray" icon="outline:arrow-up" wire:click="up">Nahoru</x-wire::button>
        @endif
        @foreach ($this->breadcrumbs() as $i => $crumb)
            @if ($i > 0)<span class="text-gray-400">/</span>@endif
            <button type="button" wire:click="go(@js($crumb['path']))" @class(['rounded px-1.5 py-0.5 hover:bg-gray-100 dark:hover:bg-white/5', 'font-semibold' => $loop->last, 'text-primary-600 dark:text-primary-400' => ! $loop->last])>{{ $crumb['name'] }}</button>
        @endforeach
    </nav>
    {{ $this->table }}
</div>
