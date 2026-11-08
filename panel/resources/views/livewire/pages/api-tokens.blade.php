<div class="space-y-4">
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Token jedná za vás: vidí a mění jen to, co vidíte v panelu vy. Použijete ho v REST API
        (<span class="font-mono">{{ rtrim(config('app.url'), '/') }}/api/v1</span>, hlavička <span class="font-mono">Authorization: Bearer …</span>)
        nebo v příkazu <span class="font-mono">wspanel</span>. Token se ukáže jen jednou; panel si ukládá jen jeho otisk.
    </p>
    @livewire(\App\Livewire\ApiTokenTable::class)
</div>
