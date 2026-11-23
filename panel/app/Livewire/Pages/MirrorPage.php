<?php

namespace App\Livewire\Pages;

use App\Actions\Server\ManageMirror;
use App\Livewire\Concerns\TranslatesPageText;
use App\Livewire\Concerns\WatchesBackgroundJobs;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Actions\Action;
use Illuminate\Support\Carbon;
use NyonCode\WireForms\Components\Tags;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireForms\Components\Toggle;
use NyonCode\WirePanels\Pages\Page;

/**
 * Server → Zrcadlo: this server and its mirror. The page shows what the
 * agent knows (it refreshes every few seconds) and starts what changes
 * roles; the failover itself is the standby agent's decision.
 */
class MirrorPage extends Page
{
    use TranslatesPageText;
    use WatchesBackgroundJobs;

    protected static string $view = 'livewire.pages.mirror';

    /** Server-wide settings: administrators only. */
    protected static ?string $permission = 'admin';

    protected ?string $title = 'Zrcadlo';

    protected static ?string $slug = 'mirror';

    protected static ?string $navigationLabel = 'Zrcadlo';

    protected static ?string $navigationIcon = 'outline:square-2-stack';

    protected static ?string $navigationGroup = 'server';

    protected static int $navigationSort = 46;

    /** @var array<string, mixed> */
    public array $state = [];

    public ?string $error = null;

    public function mount(): void
    {
        $this->refreshState();
    }

    public function refreshState(): void
    {
        try {
            $this->state = app(ManageMirror::class)->status();
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function backgroundJobKeys(): array
    {
        return ['mirror'];
    }

    protected function backgroundJobFinished(string $key, bool $ok): void
    {
        $this->refreshState();
    }

    public function role(): string
    {
        return (string) ($this->state['role'] ?? '');
    }

    public function roleLabel(?string $role = null): string
    {
        return __(ManageMirror::ROLES[$role ?? $this->role()] ?? (string) $role);
    }

    public function roleColor(?string $role = null): string
    {
        return match ($role ?? $this->role()) {
            'primary' => 'success',
            'standby' => 'info',
            'fenced' => 'danger',
            default => 'gray',
        };
    }

    public function paired(): bool
    {
        return ! empty($this->state['peer']);
    }

    /** @return array<string, mixed> */
    public function runtime(): array
    {
        return (array) ($this->state['status'] ?? []);
    }

    /** @return list<array{key: string, label: string, ok: bool, detail: string, error: string, lag: ?float, at: ?string}> */
    public function layers(): array
    {
        $have = (array) ($this->runtime()['layers'] ?? []);
        $out = [];
        foreach (ManageMirror::LAYERS as $key => $label) {
            if (! isset($have[$key])) {
                continue;
            }
            $l = $have[$key];
            $out[] = [
                'key' => $key, 'label' => __($label), 'ok' => (bool) ($l['ok'] ?? false), 'detail' => (string) ($l['detail'] ?? ''),
                'error' => (string) ($l['error'] ?? ''), 'lag' => isset($l['lag_seconds']) ? (float) $l['lag_seconds'] : null, 'at' => $l['at'] ?? null,
            ];
        }

        return $out;
    }

    /** @return list<array{at: string, level: string, message: string}> */
    public function events(): array
    {
        return array_reverse(array_slice((array) ($this->runtime()['events'] ?? []), -20));
    }

    public function ago(?string $at): string
    {
        if ($at === null || $at === '' || str_starts_with($at, '0001')) {
            return '—';
        }

        return Carbon::parse($at)->diffForHumans();
    }

    protected function headerActions(): array
    {
        $role = $this->role();
        $id = (string) ($this->state['node_id'] ?? '');
        $m = fn () => app(ManageMirror::class);
        $run = function (callable $fn, string $ok, string $failed): void {
            try {
                $fn();
                Toast::success($ok);
            } catch (AgentException $e) {
                Toast::error($failed, $e->getMessage());
            }
            $this->refreshState();
        };

        return [
            Action::make('init')
                ->label(__('Nastavit jako primár'))
                ->icon('outline:server')
                ->visible($role === '')
                ->slideOver()
                ->modalHeading(__('Tento server jako primár zrcadla'))
                ->modalDescription(__('Server dál obsluhuje weby. Vytvoří se certifikační autorita zrcadla a jednorázový kód (platí hodinu), kterým se k němu připojí zrcadlo. Port zrcadla se otevře jen pro adresu zrcadla.'))
                ->form([
                    TextInput::make('address')->label(__('Adresa tohoto serveru (jak ho uvidí zrcadlo)'))->required()->placeholder('192.168.1.10')->rules(['required', 'max:253']),
                    TextInput::make('peer_address')->label(__('Adresa zrcadla (odkud se bude připojovat)'))->required()->placeholder('192.168.1.20')
                        ->helperText(__('Adresa zrcadla v LAN (u VM v Hyper-V její vlastní IP).'))->rules(['required', 'max:253']),
                    TextInput::make('lan_address')->label(__('IP pro lokální DNS (výchozí: adresa výše)'))->rules(['nullable', 'ip']),
                    TextInput::make('public_ipv4')->label(__('Veřejná IPv4 (pro Cloudflare, nepovinné)'))->rules(['nullable', 'ipv4']),
                    TextInput::make('port')->label(__('Port zrcadla'))->integer()->default(7443)->rules(['nullable', 'integer', 'between:1024,65535']),
                ])
                ->action(function (array $data) use ($m) {
                    try {
                        $out = $m()->init($data);
                        Toast::secret(__('Párovací kód pro zrcadlo (platí hodinu)'), (string) $out['code']);
                    } catch (AgentException $e) {
                        Toast::error(__('Zrcadlo se nepodařilo nastavit'), $e->getMessage());
                    }
                    $this->refreshState();
                }),
            Action::make('join')
                ->label(__('Připojit jako zrcadlo'))
                ->icon('outline:square-2-stack')
                ->color('gray')
                ->visible($role === '')
                ->slideOver()
                ->modalHeading(__('Tento server jako zrcadlo (standby)'))
                ->modalDescription(__('Server se stane kopií primáru: stáhne jeho stav, weby, poštu a databáze a bude je průběžně synchronizovat. Musí být prázdný (bez webů). Software, který primár používá (enginy, verze PHP, moduly), se doinstaluje sám.'))
                ->form([
                    TextInput::make('primary')->label(__('Adresa primáru'))->required()->placeholder('192.168.1.10')->rules(['required', 'max:253']),
                    TextInput::make('port')->label(__('Port zrcadla na primáru'))->integer()->default(7443)->rules(['nullable', 'integer', 'between:1024,65535']),
                    TextInput::make('code')->label(__('Párovací kód'))->required()->placeholder('WSPM1-…')->rules(['required', 'regex:/^WSPM1-[A-Z2-7]+-[A-Z2-7]+$/i']),
                    TextInput::make('address')->label(__('Adresa tohoto serveru (jak ho uvidí primár)'))->required()
                        ->helperText(__('Adresa tohoto serveru v LAN (u VM v Hyper-V její vlastní IP).'))->rules(['required', 'max:253']),
                    TextInput::make('local_port')->label(__('Port zrcadla na tomto serveru'))->integer()->default(7443)->rules(['nullable', 'integer', 'between:1024,65535']),
                    TextInput::make('lan_address')->label(__('IP pro lokální DNS, až bude primárem'))->rules(['nullable', 'ip']),
                    TextInput::make('public_ipv4')->label(__('Veřejná IPv4 (pro Cloudflare, nepovinné)'))->rules(['nullable', 'ipv4']),
                ])
                ->action(function (array $data) use ($m) {
                    try {
                        $data['code'] = strtoupper(trim((string) $data['code']));
                        $out = $m()->join($data);
                        Toast::success(__('Připojeno jako zrcadlo'), $out['warning'] ?? __('První synchronizace začíná.'));
                    } catch (AgentException $e) {
                        Toast::error(__('Připojení se nepodařilo'), $e->getMessage());
                    }
                    $this->refreshState();
                }),
            Action::make('pairingCode')
                ->label(__('Nový párovací kód'))
                ->icon('outline:key')
                ->color('gray')
                ->visible($role === 'primary' && ! $this->paired())
                ->action(function () use ($m) {
                    try {
                        $out = $m()->pairingCode();
                        Toast::secret(__('Párovací kód pro zrcadlo (platí hodinu)'), (string) $out['code']);
                    } catch (AgentException $e) {
                        Toast::error(__('Kód se nepodařilo vytvořit'), $e->getMessage());
                    }
                }),
            Action::make('syncNow')
                ->label(__('Synchronizovat teď'))
                ->icon('outline:arrow-path')
                ->color('gray')
                ->visible($role === 'standby')
                ->action(fn () => $run(fn () => $m()->syncNow(), __('Synchronizace začala'), __('Synchronizace nejde'))),
            Action::make('promote')
                ->label(__('Povýšit na primár'))
                ->icon('outline:arrow-up-circle')
                ->color('danger')
                ->visible($role === 'standby')
                ->modalHeading(__('Povýšit toto zrcadlo na primár'))
                ->modalDescription(__('Když primár odpovídá, proběhne plánované přepnutí: primár přestane přijímat zápisy, zrcadlo ho dožene a převezme provoz bez ztráty dat. Když neodpovídá, zrcadlo převezme provoz s tím, co má (změny od poslední synchronizace se mohou ztratit) a starý primár se po návratu sám odstaví. Pro potvrzení opište ID tohoto serveru.'))
                ->form([
                    TextInput::make('confirm')->label(__('ID tohoto serveru: :id', ['id' => $id]))->required()->rules(['required', 'in:'.$id]),
                ])
                ->action(function () use ($m, $id) {
                    try {
                        $m()->promote($id);
                    } catch (AgentException $e) {
                        Toast::error(__('Povýšení nejde'), $e->getMessage());
                    }
                    $this->loadBackgroundJobs();
                }),
            Action::make('switchover')
                ->label(__('Plánované přepnutí'))
                ->icon('outline:arrows-right-left')
                ->color('warning')
                ->visible($role === 'primary' && $this->paired())
                ->requiresConfirmation()
                ->modalHeading(__('Přepnout provoz na zrcadlo'))
                ->modalDescription(__('Tento server přestane přijímat zápisy, zrcadlo ho dožene (databáze, soubory, stav) a převezme provoz bez ztráty dat. Tento server se pak stane jeho zrcadlem. Weby budou pár desítek sekund nedostupné. Hodí se před údržbou tohoto serveru.'))
                ->action(function () use ($m) {
                    try {
                        $m()->switchover();
                    } catch (AgentException $e) {
                        Toast::error(__('Přepnutí nejde'), $e->getMessage());
                    }
                    $this->loadBackgroundJobs();
                }),
            Action::make('rejoin')
                ->label(__('Připojit zpět jako zrcadlo'))
                ->icon('outline:arrow-uturn-left')
                ->visible($role === 'fenced')
                ->requiresConfirmation()
                ->modalDescription(__('Druhý server je teď primár. Tento server se stane jeho zrcadlem: databáze se znovu naplní z primáru (co měl jen tento server, se nejdřív uloží stranou do /var/backups/wspanel/mirror), soubory se srovnají s primárem (rozdíly se uloží stranou). Pak půjde plánovaně přepnout zpět.'))
                ->action(fn () => $run(fn () => $m()->rejoin(), __('Server je teď zrcadlo'), __('Připojení zpět nejde'))),
            Action::make('unfence')
                ->label(__('Znovu obsluhovat weby'))
                ->icon('outline:play')
                ->color('gray')
                ->visible($role === 'fenced')
                ->requiresConfirmation()
                ->modalDescription(__('Jen když druhý server primárem není (např. nedokončené přepnutí). Panel to ověří: když je druhý server primár, odmítne to.'))
                ->action(fn () => $run(fn () => $m()->unfence(), __('Server znovu obsluhuje weby'), __('Nejde'))),
            Action::make('settings')
                ->label(__('Nastavení'))
                ->icon('outline:cog-6-tooth')
                ->color('gray')
                ->visible($role !== '')
                ->slideOver()
                ->modalHeading(__('Nastavení zrcadla'))
                ->modalDescription(__('Platí pro tento server. Automatické přepnutí nastavte stejně na obou.'))
                ->fillFormUsing(fn () => [
                    'witness' => (array) ($this->state['witness'] ?? []),
                    'auto_failover' => (bool) ($this->state['auto_failover'] ?? false),
                    'failover_after' => (int) ($this->state['failover_after'] ?? 60),
                    'sync_every' => (int) ($this->state['sync_every'] ?? 60),
                    'lan_address' => (string) ($this->state['lan_address'] ?? ''),
                    'public_ipv4' => (string) ($this->state['public_ipv4'] ?? ''),
                ])
                ->form([
                    Tags::make('witness')->label(__('Svědci'))->placeholder('192.168.1.1')
                        ->helperText(__('Brána LAN nebo jiný stroj, který vidí oba servery: IP (ping) nebo host:port (TCP, např. řadič domény 192.168.1.5:53). Zrcadlo se samo povýší jen tehdy, když primár neodpovídá a většina svědků ano; jinak je odříznuté ono.')),
                    Toggle::make('auto_failover')->label(__('Automatické přepnutí'))
                        ->helperText(__('Bez svědka ho nejde zapnout. Primár se při ztrátě spojení se zrcadlem i svědky sám odstaví (po polovině doby), aby nikdy neběžely dva primáry.')),
                    TextInput::make('failover_after')->label(__('Přepnout po (s)'))->integer()->rules(['integer', 'between:20,3600']),
                    TextInput::make('sync_every')->label(__('Synchronizace souborů a stavu každých (s)'))->integer()->rules(['integer', 'between:15,3600'])
                        ->helperText(__('Databáze se replikují průběžně; změny na primáru (deploy, nastavení) spouští synchronizaci hned.')),
                    TextInput::make('lan_address')->label(__('IP pro lokální DNS'))->rules(['required', 'ip']),
                    TextInput::make('public_ipv4')->label(__('Veřejná IPv4 (pro Cloudflare)'))->rules(['nullable', 'ipv4']),
                ])
                ->action(function (array $data) use ($m) {
                    try {
                        $m()->settings([
                            'witness' => array_values(array_filter(array_map('trim', (array) ($data['witness'] ?? [])))),
                            'failover_after' => (int) $data['failover_after'],
                            'sync_every' => (int) $data['sync_every'],
                            'lan_address' => (string) $data['lan_address'],
                            'public_ipv4' => (string) ($data['public_ipv4'] ?? ''),
                        ]);
                        $m()->settings(['auto_failover' => (bool) ($data['auto_failover'] ?? false)]);
                        Toast::success(__('Uloženo'));
                    } catch (AgentException $e) {
                        Toast::error(__('Nastavení se nepodařilo uložit'), $e->getMessage());
                    }
                    $this->refreshState();
                }),
            Action::make('windns')
                ->label(__('Windows DNS'))
                ->icon('outline:globe-alt')
                ->color('gray')
                ->visible($role !== '')
                ->slideOver()
                ->modalHeading(__('Windows DNS (Active Directory)'))
                ->modalDescription(__('Aktivní server drží A záznamy svých webů v zónách Windows DNS na své IP, takže po přepnutí klienti v LAN jdou na nový primár do TTL. Panel se připojí přes OpenSSH na Windows k účtu, který smí spustit jen skript wspanel-dns.ps1 (Install-WSPanelDns.ps1 v provision/files/windows). Nastavuje se na každém serveru zvlášť: každý má svůj klíč.'))
                ->fillFormUsing(fn () => [
                    'host' => (string) ($this->state['windns']['host'] ?? ''),
                    'port' => (int) ($this->state['windns']['port'] ?? 22),
                    'user' => (string) ($this->state['windns']['user'] ?? ''),
                    'zones' => (array) ($this->state['windns']['zones'] ?? []),
                    'ttl' => (int) ($this->state['windns']['ttl'] ?? 60),
                    'extra' => (array) ($this->state['windns']['extra'] ?? []),
                ])
                ->form([
                    TextInput::make('host')->label(__('Windows server (DNS)'))->required()->placeholder('192.168.1.5')->rules(['required', 'max:253']),
                    TextInput::make('port')->label(__('Port SSH'))->integer()->rules(['integer', 'between:1,65535']),
                    TextInput::make('user')->label(__('Účet'))->required()->placeholder('wspanel-dns@firma.local')->rules(['required', 'regex:/^[A-Za-z0-9._-]{1,64}(@[A-Za-z0-9.-]{1,190})?$/']),
                    Tags::make('zones')->label(__('Zóny'))->placeholder('firma.local')->required(),
                    TextInput::make('ttl')->label(__('TTL záznamů (s)'))->integer()->rules(['integer', 'between:30,86400'])
                        ->helperText(__('Krátké TTL = rychlejší přepnutí. 60 s je rozumný kompromis.')),
                    Tags::make('extra')->label(__('Další jména, která následují aktivní server'))->placeholder('panel.firma.local')
                        ->helperText(__('Adresa panelu, poštovní server… Domény webů a jejich aliasy se berou samy.')),
                ])
                ->action(function (array $data) use ($m) {
                    try {
                        $out = $m()->winDnsSet([
                            'host' => (string) $data['host'], 'port' => (int) ($data['port'] ?: 22), 'user' => (string) $data['user'],
                            'zones' => array_values((array) $data['zones']), 'ttl' => (int) ($data['ttl'] ?: 60), 'extra' => array_values((array) ($data['extra'] ?? [])),
                        ]);
                        Toast::secret(__('Veřejný klíč tohoto serveru (do -PublicKey skriptu na Windows)'), (string) $out['public_key']);
                    } catch (AgentException $e) {
                        Toast::error(__('Nepodařilo se uložit'), $e->getMessage());
                    }
                    $this->refreshState();
                }),
            Action::make('windnsTrust')
                ->label(__('Ověřit hostitele Windows'))
                ->icon('outline:finger-print')
                ->color('gray')
                ->visible(! empty($this->state['windns']))
                ->requiresConfirmation()
                ->modalDescription(__('Panel načte klíč hostitele Windows a připne ho. Porovnejte otisk s tím, který vypsal Install-WSPanelDns.ps1: když se liší, nastavení smažte (někdo se vydává za server).'))
                ->action(function () use ($m) {
                    try {
                        $out = $m()->winDnsTrust();
                        Toast::secret(__('Otisk klíče hostitele (porovnejte s Windows)'), (string) ($out['fingerprint'] ?: $out['host_key']));
                    } catch (AgentException $e) {
                        Toast::error(__('Ověření selhalo'), $e->getMessage());
                    }
                    $this->refreshState();
                }),
            Action::make('windnsTest')
                ->label(__('Vyzkoušet Windows DNS'))
                ->icon('outline:beaker')
                ->color('gray')
                ->visible(! empty($this->state['windns']['trusted']))
                ->action(function () use ($m) {
                    try {
                        $out = $m()->winDnsTest();
                        Toast::success(__('Windows DNS odpovídá'), __('Zóna :zone: :n záznamů A/AAAA', ['zone' => $out['zone'], 'n' => count($out['records'] ?? [])]));
                    } catch (AgentException $e) {
                        Toast::error(__('Windows DNS neodpovídá'), $e->getMessage());
                    }
                }),
            Action::make('unpair')
                ->label(__('Zrušit zrcadlo'))
                ->icon('outline:trash')
                ->color('gray')
                ->visible($role !== '')
                ->modalDescription(__('Tento server opustí zrcadlo. Nic se nesmaže: zrcadlo si nechá kopie (replikace se zastaví, databáze začnou přijímat zápisy), primár dál obsluhuje weby. Pro potvrzení opište ID tohoto serveru.'))
                ->form([
                    TextInput::make('confirm')->label(__('ID tohoto serveru: :id', ['id' => $id]))->required()->rules(['required', 'in:'.$id]),
                ])
                ->action(fn () => $run(fn () => $m()->unpair($id), __('Zrcadlo zrušeno'), __('Zrušení nejde'))),
        ];
    }
}
