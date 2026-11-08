<div class="space-y-4">
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Posledních 500 operací, které panel požádal agenta provést. Citlivé hodnoty (obsah .env, hesla, klíče, tokeny) agent do logu nezapisuje. Celý log je na serveru v /var/log/panel-agent/audit.log.') }}</p>
    @livewire(\App\Livewire\AuditTable::class)
</div>
