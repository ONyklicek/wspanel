<div class="space-y-6">
    @if ($error)
        <x-wire::section>
            <p class="text-sm text-red-600">{{ __('Stav se nepodařilo načíst:') }} {{ $error }}</p>
        </x-wire::section>
    @else
        @php($c = $status['config'])
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2"><x-wire::section :heading="__('Jak to funguje')">
                <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <p>{{ __('Zálohy na serveru neochrání před ztrátou serveru nebo disku. Když nastavíte úložiště –') }} <strong>S3</strong> (Amazon S3, Cloudflare R2, Backblaze B2, Wasabi, MinIO…), <strong>FTP/FTPS</strong> {{ __('nebo sdílenou složku') }} <strong>SMB</strong> {{ __('(NAS, Windows, Hetzner Storage Box) – každá nová záloha webu i databáze – ruční i plánovaná – se hned nahraje i tam.') }}</p>
                    <p>{{ __('Kopie leží v') }} <span class="font-mono">{!! __('sites/&lt;doména&gt;/') !!}</span> a <span class="font-mono">{!! __('db/&lt;engine&gt;/&lt;databáze&gt;/') !!}</span>{{ __('. Kopie starší než nastavený počet dní se mažou, nejnovější zůstává vždy. Zálohy pořízené těsně před obnovou se nenahrávají.') }}</p>
                    <p>{{ __('Zálohu, která už na serveru není, stáhnete zpět na záložce') }} <strong>{{ __('Zálohy') }}</strong> {{ __('u webu a obnovíte ji jako každou jinou.') }}</p>
                    <p>{{ __('Každá kopie je') }} <strong>{{ __('podepsaná') }}</strong> {{ __('klíčem, který zná jen tento server. Panel stáhne zpět jen kopii s platným podpisem, takže podvrženou nebo pozměněnou zálohu z úložiště neobnoví. Klíč si uložte (na serveru ho vypíše') }} <code>sudo panel-agent -recovery-key</code>{{ __('): bez něj nový server staré zálohy neověří.') }}</p>
                    <p>{{ __('Zálohy obsahují') }} <span class="font-mono">.env</span> {{ __('a data webů: použijte soukromý bucket s klíčem jen pro něj, ideálně se zapnutým šifrováním a verzováním na straně úložiště.') }}</p>
                </div>
            </x-wire::section></div>
            <x-wire::section :heading="__('Stav')">
                <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                    <dt class="text-gray-500">{{ __('Kopírování') }}</dt>
                    <dd><x-wire::badge :color="$this->enabled() ? 'success' : 'gray'">{{ $this->enabled() ? __('zapnuto') : ($status['configured'] ? __('vypnuto') : __('nenastaveno')) }}</x-wire::badge></dd>
                    @if ($status['configured'])
                        <dt class="text-gray-500">{{ __('Typ') }}</dt><dd>{{ $this->typeLabel() }}</dd>
                        <dt class="text-gray-500">{{ __('Kam') }}</dt><dd class="break-all font-mono text-xs">{{ $this->where() }}</dd>
                        <dt class="text-gray-500">{{ __('Uchovávat') }}</dt><dd>{{ $c['keep_days'] ?? 30 }} {{ __('dní') }}</dd>
                    @endif
                </dl>
            </x-wire::section>
        </div>

        <x-wire::section :heading="__('Záloha serveru')"
            :description="__('Každou noc: stav panelu a agenta, konfigurace webů, účty databází, DKIM klíče, certifikáty a DNS zóny (šifrovaně klíčem pro obnovu), a vedle ní pošta každé domény. S kopiemi webů a databází v úložišti z ní příkaz wspanel-restore postaví na novém serveru tento server znovu.')">
            @php($backups = $this->serverBackups())
            @if ($backups === [])
                <p class="text-sm text-gray-500">{{ __('Zatím žádná. První vznikne v noci, nebo hned tlačítkem Zálohovat server teď.') }}</p>
            @else
                <ul class="divide-y divide-gray-100 text-sm dark:divide-white/5">
                    @foreach ($backups as $b)
                        <li class="flex justify-between gap-3 py-1.5">
                            <span class="font-mono text-xs">{{ $b['file'] }}</span>
                            <span class="text-gray-500">{{ \Illuminate\Support\Carbon::parse($b['created'])->format('j. n. Y H:i') }} · {{ \Illuminate\Support\Number::fileSize($b['size'], 1) }}</span>
                        </li>
                    @endforeach
                </ul>
                @if (! $this->enabled())
                    <p class="mt-2 text-sm text-amber-600">{{ __('Bez úložiště mimo server leží zálohy jen tady: při ztrátě serveru se ztratí s ním.') }}</p>
                @endif
            @endif
        </x-wire::section>
    @endif
</div>
