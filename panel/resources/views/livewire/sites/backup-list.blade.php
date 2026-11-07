<div class="space-y-3">
    <p class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
        <x-wire::icon name="outline:clock" class="h-4 w-4 shrink-0 text-gray-400" />
        {{ $this->scheduleLabel() }}
    </p>
    @if ($offsiteError)
        <p class="flex items-center gap-2 text-sm text-amber-600 dark:text-amber-400">
            <x-wire::icon name="outline:exclamation-triangle" class="h-4 w-4 shrink-0" />
            Kopie mimo server se nepodařilo načíst: {{ $offsiteError }}
        </p>
    @elseif (! $offsite)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Zálohy jsou jen na tomto serveru. Kopírování mimo server nastavíte v <a href="{{ \App\Livewire\Pages\OffsitePage::url() }}" class="text-primary-600 hover:underline dark:text-primary-400">Server → Zálohy mimo server</a>.
        </p>
    @endif
    {{ $this->table }}
</div>
