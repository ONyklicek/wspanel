<div class="space-y-4">
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('Token jedná za vás: vidí a mění jen to, co vidíte v panelu vy. Použijete ho v REST API (') }}<span class="font-mono">{{ rtrim(config('app.url'), '/') }}/api/v1</span>{{ __(', hlavička') }} <span class="font-mono">{{ __('Authorization: Bearer …') }}</span>{{ __(') nebo v příkazu') }} <span class="font-mono">wspanel</span>{{ __('. Token se ukáže jen jednou; panel si ukládá jen jeho otisk.') }}
    </p>
    @livewire(\App\Livewire\ApiTokenTable::class)
    @livewire(\App\Livewire\GithubConnectionTable::class)
</div>
