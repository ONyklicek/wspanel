<div @if ($follow) wire:poll.3s="tail" @endif data-testid="game-console"
    class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800"
    x-data="{
        history: @js(array_values(array_reverse(array_column($sent, 'command')))),
        at: -1,
        stick: true,
        box: null,
        init() {
            this.box = this.$refs.out;
            this.down();
            new MutationObserver(() => this.stick && this.down()).observe(this.box, { childList: true, subtree: true });
        },
        down() { this.box.scrollTop = this.box.scrollHeight },
        scrolled() { this.stick = this.box.scrollHeight - this.box.scrollTop - this.box.clientHeight < 24 },
        prev() { if (this.at + 1 < this.history.length) { this.at++; $wire.command = this.history[this.at] } },
        next() { if (this.at > 0) { this.at--; $wire.command = this.history[this.at] } else { this.at = -1; $wire.command = '' } },
    }"
    x-on:game-command-sent.window="history.unshift($event.detail.command); at = -1; stick = true">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 sm:px-6 dark:border-gray-700">
        <div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Konzole') }}</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $server->game->hasConsole() ? __('Log serveru tak, jak se zapisuje, a příkazy s odpověďmi. Šipkami ↑ ↓ projdete poslané příkazy.') : __('Log serveru tak, jak se zapisuje.') }}</p>
        </div>
        <div class="flex items-center gap-3">
            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                <input type="checkbox" wire:model.live="follow" class="rounded border-gray-300 dark:border-white/10 dark:bg-white/5">
                {{ __('Sledovat živě') }}
                @if ($follow)
                    <span class="relative flex size-2"><span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-500 opacity-60"></span><span class="relative inline-flex size-2 rounded-full bg-emerald-500"></span></span>
                @endif
            </label>
            @unless ($follow)
                <x-wire::button size="xs" color="gray" outlined icon="outline:arrow-path" wire:click="tail">{{ __('Obnovit') }}</x-wire::button>
            @endunless
        </div>
    </div>

    <div class="relative">
        <div x-ref="out" x-on:scroll.passive="scrolled()" class="h-96 overflow-y-auto bg-gray-950 px-3 py-2 font-mono text-xs leading-5 sm:h-[28rem]" role="log" aria-live="off">
            @forelse ($entries as $i => $e)
                @if ($e['kind'] === 'command')
                    <div wire:key="c-{{ $i }}-{{ md5($e['time'].$e['text']) }}" class="my-1 rounded border-l-2 border-primary-400 bg-white/5 py-0.5 ps-2">
                        <div class="text-primary-300"><span class="me-2 text-gray-500">{{ $e['time'] }}</span>&gt; {{ $e['text'] }}</div>
                        <div class="whitespace-pre-wrap break-words text-gray-100">{{ $e['output'] !== '' ? $e['output'] : __('(bez odpovědi)') }}</div>
                    </div>
                @else
                    <div wire:key="l-{{ $i }}-{{ md5($e['time'].$e['text']) }}" class="whitespace-pre-wrap break-words {{ \App\Livewire\Games\GameConsole::tone($e['text']) }}"><span class="me-2 select-none text-gray-500">{{ $e['time'] }}</span>{{ $e['text'] }}</div>
                @endif
            @empty
                <div class="text-gray-500">{{ $server->status === 'installing' ? __('Server se instaluje, log se objeví po spuštění.') : __('Log je prázdný.') }}</div>
            @endforelse
        </div>
        <button type="button" x-show="!stick" x-cloak x-on:click="stick = true; down()"
            class="absolute bottom-3 end-5 inline-flex items-center gap-1 rounded-full bg-primary-600 px-3 py-1 text-xs font-medium text-white shadow hover:bg-primary-500">
            {!! icon('outline:arrow-down', 'size-3.5') !!} {{ __('Na konec') }}
        </button>
    </div>

    <div class="space-y-2 px-4 py-3 sm:px-6">
        @if ($error)
            <p class="text-sm text-red-600 dark:text-red-400">{{ __('Log se nepodařilo načíst:') }} {{ $error }}</p>
        @endif
        @unless ($server->game->hasConsole())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Tahle hra nemá konzoli, kterou by panel mohl použít: příkazy zadáte v jejích souborech nebo ve hře.') }}</p>
        @else
        <form wire:submit="send" class="flex gap-2">
            <div class="relative flex-1">
                <span class="pointer-events-none absolute inset-y-0 start-3 flex items-center font-mono text-sm text-gray-400">&gt;</span>
                <input type="text" x-ref="input" wire:model="command" maxlength="1000" autocomplete="off" spellcheck="false"
                    x-on:keydown.arrow-up.prevent="prev()" x-on:keydown.arrow-down.prevent="next()"
                    placeholder="{{ $running ? __('Příkaz serveru') : __('Server neběží') }}" aria-label="{{ __('Příkaz') }}"
                    class="w-full rounded-lg border-gray-300 ps-7 font-mono text-sm dark:border-white/10 dark:bg-white/5" @disabled(! $running) data-testid="game-console-input">
            </div>
            <x-wire::button type="submit" size="sm" icon="outline:paper-airplane" :disabled="! $running" wire:loading.attr="disabled" wire:target="send">{{ __('Odeslat') }}</x-wire::button>
        </form>
        @error('command')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        @endunless
        @if ($running && $server->game->hasConsole())
            <div class="flex flex-wrap gap-1.5">
                @foreach ($this->suggestions() as $s)
                    <button type="button" wire:key="sg-{{ $s }}" x-on:click="$wire.command = @js($s); $nextTick(() => $refs.input.focus())"
                        class="rounded-md border border-gray-200 px-2 py-0.5 font-mono text-xs text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5">{{ trim($s) }}</button>
                @endforeach
                @if ($sent !== [])
                    <button type="button" wire:click="clearSent" class="ms-auto text-xs text-gray-500 hover:underline">{{ __('Skrýt odeslané příkazy') }}</button>
                @endif
            </div>
        @endif
    </div>
</div>
