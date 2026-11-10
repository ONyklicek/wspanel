<form wire:submit="save" class="space-y-6" data-testid="game-options">
    @if ($error)
        <x-wire::callout color="danger" :heading="__('Agent neodpovídá')">{{ $error }}</x-wire::callout>
    @endif
    <x-wire::section :heading="__('Nastavení hry')" :description="__('Volby, které hra dostane při spuštění. Platí po restartu serveru; ostatní nastavení hry upravíte v jejích souborech (záložka Soubory).')">
        <div class="grid gap-x-6 gap-y-5 sm:grid-cols-2">
            @foreach ($specs as $s)
                @php($key = $s['key'])
                @php($id = 'opt-'.$key)
                <div wire:key="opt-{{ $key }}" @class(['sm:col-span-2' => $s['type'] === 'secret'])>
                    @if ($s['type'] === 'bool')
                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                            <input type="checkbox" wire:model="values.{{ $key }}" class="rounded border-gray-300 dark:border-white/10 dark:bg-white/5">{{ $s['label'] }}
                        </label>
                    @else
                        <label for="{{ $id }}" class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                            {{ $s['label'] }}
                            @if ($s['type'] === 'secret' && ($secretsSet[$key] ?? false))
                                <x-wire::badge color="success">{{ __('nastaveno') }}</x-wire::badge>
                            @endif
                        </label>
                        @if ($s['type'] === 'select')
                            <select id="{{ $id }}" wire:model="values.{{ $key }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                                @unless ($s['required'] ?? false)<option value="">—</option>@endunless
                                @foreach ($s['options'] as $o)
                                    <option value="{{ $o['value'] }}">{{ $o['label'] }}</option>
                                @endforeach
                            </select>
                        @elseif ($s['type'] === 'secret')
                            <div class="mt-1 flex flex-wrap gap-2">
                                <input id="{{ $id }}" type="password" wire:model="secrets.{{ $key }}" autocomplete="new-password"
                                    placeholder="{{ ($secretsSet[$key] ?? false) ? __('ponechat současné') : '' }}"
                                    class="min-w-0 flex-1 rounded-lg border-gray-300 font-mono text-sm dark:border-white/10 dark:bg-white/5">
                                @if (($secretsSet[$key] ?? false) && ! ($s['required'] ?? false))
                                    <x-wire::button size="sm" color="danger" outlined wire:click="clearSecret('{{ $key }}')" wire:confirm="{{ __('Odebrat :label?', ['label' => $s['label']]) }}">{{ __('Odebrat') }}</x-wire::button>
                                @endif
                            </div>
                        @else
                            <input id="{{ $id }}" type="{{ $s['type'] === 'int' ? 'number' : 'text' }}" wire:model="values.{{ $key }}"
                                @isset($s['min']) min="{{ $s['min'] }}" @endisset @isset($s['max']) max="{{ $s['max'] }}" @endisset
                                @if ($s['required'] ?? false) required @endif maxlength="128"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                        @endif
                    @endif
                    @if (($s['help'] ?? '') !== '')
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $s['help'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </x-wire::section>
    <div class="flex flex-wrap items-center gap-2">
        <x-wire::button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('Uložit') }}</x-wire::button>
        <x-wire::button type="button" color="gray" outlined icon="outline:arrow-path" wire:click="save(true)" wire:loading.attr="disabled" wire:target="save">{{ __('Uložit a restartovat') }}</x-wire::button>
    </div>
</form>
