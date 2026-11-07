<div class="space-y-6">
    @if ($error)
        <x-wire::section>
            <p class="text-sm text-red-600">Stav se nepodařilo načíst: {{ $error }}</p>
        </x-wire::section>
    @else
        @php($c = $status['config'])
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2"><x-wire::section heading="Jak to funguje">
                <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <p>Zálohy na serveru neochrání před ztrátou serveru nebo disku. Když nastavíte úložiště – <strong>S3</strong> (Amazon S3, Cloudflare R2, Backblaze B2, Wasabi, MinIO…), <strong>FTP/FTPS</strong> nebo sdílenou složku <strong>SMB</strong> (NAS, Windows, Hetzner Storage Box) – každá nová záloha webu i databáze – ruční i plánovaná – se hned nahraje i tam.</p>
                    <p>Kopie leží v <span class="font-mono">sites/&lt;doména&gt;/</span> a <span class="font-mono">db/&lt;engine&gt;/&lt;databáze&gt;/</span>. Kopie starší než nastavený počet dní se mažou, nejnovější zůstává vždy. Zálohy pořízené těsně před obnovou se nenahrávají.</p>
                    <p>Zálohu, která už na serveru není, stáhnete zpět na záložce <strong>Zálohy</strong> u webu a obnovíte ji jako každou jinou.</p>
                    <p>Každá kopie je <strong>podepsaná</strong> klíčem, který zná jen tento server. Panel stáhne zpět jen kopii s platným podpisem, takže podvrženou nebo pozměněnou zálohu z úložiště neobnoví. Klíč si uložte (tlačítko <em>Klíč pro ověření záloh</em>): bez něj nový server staré zálohy neověří.</p>
                    <p>Zálohy obsahují <span class="font-mono">.env</span> a data webů: použijte soukromý bucket s klíčem jen pro něj, ideálně se zapnutým šifrováním a verzováním na straně úložiště.</p>
                </div>
            </x-wire::section></div>
            <x-wire::section heading="Stav">
                <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                    <dt class="text-gray-500">Kopírování</dt>
                    <dd><x-wire::badge :color="$this->enabled() ? 'success' : 'gray'">{{ $this->enabled() ? 'zapnuto' : ($status['configured'] ? 'vypnuto' : 'nenastaveno') }}</x-wire::badge></dd>
                    @if ($status['configured'])
                        <dt class="text-gray-500">Typ</dt><dd>{{ $this->typeLabel() }}</dd>
                        <dt class="text-gray-500">Kam</dt><dd class="break-all font-mono text-xs">{{ $this->where() }}</dd>
                        <dt class="text-gray-500">Uchovávat</dt><dd>{{ $c['keep_days'] ?? 30 }} dní</dd>
                    @endif
                </dl>
            </x-wire::section>
        </div>
    @endif
</div>
