<ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
    @foreach ($checks as $c)
        <li class="flex items-start gap-3 py-2">
            <x-wire::badge :color="$c['ok'] === null ? 'gray' : ($c['ok'] ? 'success' : 'danger')">{{ $c['ok'] === null ? '?' : ($c['ok'] ? 'OK' : 'chybí') }}</x-wire::badge>
            <span><span class="font-medium">{{ $c['label'] }}</span><span class="block break-all font-mono text-xs text-gray-500">{{ $c['detail'] }}</span></span>
        </li>
    @endforeach
</ul>
<p class="mt-3 text-xs text-gray-500">Změny v DNS se šíří podle TTL, obvykle minuty až hodiny. Kontrola používá DNS resolver serveru.</p>
