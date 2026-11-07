<div class="space-y-3">
    <p class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
        <x-wire::icon name="outline:clock" class="h-4 w-4 shrink-0 text-gray-400" />
        {{ $this->scheduleLabel() }}
    </p>
    {{ $this->table }}
</div>
