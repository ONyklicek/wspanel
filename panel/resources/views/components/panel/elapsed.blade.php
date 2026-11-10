{{-- Time since $since, counting live in the browser (1:23, 1:02:03). It counts
     from the seconds elapsed on the server when drawn, so a browser clock set
     wrong does not matter; Livewire redraws restart it from the server's. --}}
@props(['since'])
@php($seconds = max(0, (int) now()->diffInSeconds($since, true)))
<span {{ $attributes->merge(['class' => 'tabular-nums']) }}
    x-data="{ from: Date.now() - {{ $seconds }} * 1000, now: Date.now(), timer: null,
        init() { this.timer = setInterval(() => this.now = Date.now(), 1000) },
        destroy() { clearInterval(this.timer) },
        get text() { const s = Math.max(0, Math.floor((this.now - this.from) / 1000)), h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), r = String(s % 60).padStart(2, '0');
            return h ? h + ':' + String(m).padStart(2, '0') + ':' + r : m + ':' + r } }"
    x-text="text">{{ intdiv($seconds, 60) }}:{{ str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT) }}</span>
