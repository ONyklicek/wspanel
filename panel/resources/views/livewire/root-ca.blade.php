<div class="space-y-4 text-sm">
    @if (! $ca)
        <p class="text-gray-600 dark:text-gray-300">{{ $error }}</p>
        <p class="text-gray-500">Autorita vznikne s prvním webem, který používá interní certifikát (web → Další → HTTPS / certifikát → Interní certifikační autorita).</p>
    @else
        <p>Weby s interním certifikátem prohlížeč označí jako důvěryhodné, až počítač (nebo telefon) jednou nainstaluje tento kořenový certifikát. Instalujte ho jen na zařízení, která mají weby používat.</p>
        <dl class="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1">
            <dt class="text-gray-500">Název</dt><dd>{{ $ca['subject'] }}</dd>
            <dt class="text-gray-500">Platí do</dt><dd>{{ \Illuminate\Support\Carbon::parse($ca['not_after'])->format('j. n. Y') }}</dd>
            <dt class="text-gray-500">Otisk SHA-256</dt><dd class="font-mono text-xs break-all">{{ implode(':', str_split($ca['sha256'], 2)) }}</dd>
        </dl>
        <x-wire::button :href="route('ssl.root-ca')" icon="outline:arrow-down-tray">Stáhnout wspanel-root-ca.crt</x-wire::button>
        <div>
            <h3 class="font-medium">Instalace</h3>
            <ul class="mt-2 list-disc space-y-1 ps-5 text-gray-600 dark:text-gray-300">
                <li><strong>macOS:</strong> otevřete soubor → Klíčenka → Systém, dvojklik na certifikát → Důvěra → „Vždy důvěřovat“.</li>
                <li><strong>Windows:</strong> dvojklik → Nainstalovat certifikát → Místní počítač → „Důvěryhodné kořenové certifikační autority“.</li>
                <li><strong>Linux (Debian/Ubuntu):</strong> <code>sudo cp wspanel-root-ca.crt /usr/local/share/ca-certificates/ && sudo update-ca-certificates</code>; Firefox má vlastní úložiště (Nastavení → Certifikáty → Importovat).</li>
                <li><strong>iPhone/iPad:</strong> otevřete soubor → Nastavení → Profil stažen → Instalovat; pak Obecné → Informace → Nastavení důvěry certifikátů → zapnout.</li>
                <li><strong>Android:</strong> Nastavení → Zabezpečení → Šifrování a pověření → Nainstalovat certifikát → Certifikát CA.</li>
                <li><strong>Firemní síť:</strong> rozešlete ho skupinovou politikou (GPO) nebo MDM.</li>
            </ul>
        </div>
        <p class="text-gray-500">Ověřte, že otisk po stažení sedí s otiskem výše. Autorita je jen tohoto serveru: certifikáty vydává sama a obnovuje je automaticky.</p>
    @endif
</div>
