{{-- The server's time in the top bar, ticking in the browser: from the
     server's clock when drawn (a wrong computer clock does not matter), in
     the server's time zone (Server → Systém → Časové pásmo). --}}
@auth
    @php
        $zone = app(\App\Actions\System\ManageSystem::class)->timezone();
        $now = now()->setTimezone($zone);
    @endphp
    <div data-testid="server-clock" class="hidden items-center gap-1.5 rounded-full px-2 py-1.5 text-sm text-gray-600 md:inline-flex dark:text-gray-300"
        title="{{ __('Čas serveru') }} · {{ $zone }}"
        x-data="{ offset: {{ $now->getTimestampMs() }} - Date.now(), zone: @js($zone), now: '{{ $now->format('H:i:s') }}', day: '{{ $now->format('j. n. Y') }}', timer: null,
            tick() { const d = new Date(Date.now() + this.offset);
                this.now = d.toLocaleTimeString('cs-CZ', { timeZone: this.zone, hour: '2-digit', minute: '2-digit', second: '2-digit' });
                this.day = d.toLocaleDateString('cs-CZ', { timeZone: this.zone }) },
            init() { this.tick(); this.timer = setInterval(() => this.tick(), 1000) },
            destroy() { clearInterval(this.timer) } }"
        x-bind:title="'{{ __('Čas serveru') }} · ' + day + ' · ' + zone">
        {!! icon('outline:clock', 'size-4 shrink-0 text-gray-400') !!}
        <span class="font-medium tabular-nums" x-text="now">{{ $now->format('H:i:s') }}</span>
    </div>
@endauth
