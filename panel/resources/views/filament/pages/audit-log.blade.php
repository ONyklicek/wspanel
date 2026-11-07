<x-filament-panels::page>
    <p class="text-sm text-gray-500">Posledních 500 operací agenta (jediné komponenty s root právy). Citlivé parametry, např. obsah .env, agent do logu nezapisuje – jen délku a otisk.</p>
    {{ $this->table }}
</x-filament-panels::page>
