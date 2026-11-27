<?php

namespace App\Livewire\Pages;

use App\Actions\System\ManagePackages;
use App\Actions\System\ManagePublicAddress;
use App\Actions\System\ManageSystem;
use App\Livewire\Concerns\TranslatesPageText;
use App\Livewire\Concerns\WatchesBackgroundJobs;
use App\Services\Agent\AgentException;
use App\Services\Dns\ServerDns;
use App\Support\Toast;
use App\Wire\Actions\Action;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WirePanels\Pages\Page;

/** The server itself: name, time, swap, network and disk health. */
class SystemPage extends Page
{
    use TranslatesPageText;
    use WatchesBackgroundJobs;

    protected static string $view = 'livewire.pages.system';

    protected static ?string $permission = 'admin';

    protected ?string $title = 'Systém';

    protected static ?string $slug = 'system';

    protected static ?string $navigationLabel = 'Systém';

    protected static ?string $navigationIcon = 'outline:cpu-chip';

    protected static ?string $navigationGroup = 'server';

    protected static int $navigationSort = 30;

    /** @var array<string, mixed>|null */
    public ?array $settings = null;

    /** @var array<string, mixed>|null */
    public ?array $network = null;

    /** @var array<string, mixed>|null */
    public ?array $disks = null;

    /** @var list<array{key: string, name: string, type: string, want: string, now: string, ok: bool, note: string}> the server's own records in public DNS */
    public array $dns = [];

    /** @var array{ipv4: string, ipv6: string} the addresses the records point to */
    public array $address = ['ipv4' => '', 'ipv6' => ''];

    /** @var array{ipv4: string, ipv6: string} the addresses the internet sees */
    public array $seen = ['ipv4' => '', 'ipv6' => ''];

    public bool $internal = false;

    public ?string $error = null;

    protected function backgroundJobKeys(): array
    {
        return ['packages', 'system-ntp', 'system-swap'];
    }

    /** A job of this page ended: the page as it is now. */
    protected function backgroundJobFinished(string $key, bool $ok): void
    {
        $this->load();
    }

    public function mount(): void
    {
        $this->load();
    }

    public function load(): void
    {
        $public = app(ManagePublicAddress::class);
        $this->address = $public->current();
        $this->seen = $public->detected();
        $this->internal = $public->internal();
        $this->dns = app(ServerDns::class)->cached();
        $system = app(ManageSystem::class);
        try {
            $this->settings = $system->settings();
            $this->network = $system->network();
            $this->disks = $system->disks();
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** Asks public DNS again, after a record was set or fixed. */
    public function refreshDns(): void
    {
        $this->dns = app(ServerDns::class)->refresh();
        $failing = collect($this->dns)->reject(fn (array $r) => $r['ok'])->count();
        $failing === 0
            ? Toast::success(__('DNS serveru je v pořádku'))
            : Toast::warning(__('DNS serveru: :count záznamů chybí nebo nesedí', ['count' => $failing]), __('Změna DNS se může projevit až za několik hodin (TTL).'));
    }

    /** Points the records to the address the internet sees (behind NAT). */
    public function useSeenAddress(): void
    {
        if ($this->seen['ipv4'] === '') {
            return;
        }
        try {
            app(ManagePublicAddress::class)->set($this->seen['ipv4'], $this->address['ipv6'] !== '' ? $this->address['ipv6'] : $this->seen['ipv6']);
            Toast::success(__('Veřejná adresa nastavena'), __('Navrhované DNS záznamy teď míří na :ip.', ['ip' => $this->seen['ipv4']]));
        } catch (AgentException $e) {
            Toast::error(__('Změna selhala'), $e->getMessage());
        }
        $this->load();
    }

    /** In the background: switching it on may install systemd-timesyncd first. */
    public function toggleNtp(): void
    {
        $on = ! ($this->settings['ntp'] ?? false);
        $this->queueBackgroundJob('system-ntp', [
            'title' => $on ? __('Zapnutí synchronizace času') : __('Vypnutí synchronizace času'),
            'done' => $on ? __('Synchronizace času zapnuta') : __('Synchronizace času vypnuta'),
        ], ManageSystem::class, 'setNtp', [$on]);
    }

    public function installSmart(): void
    {
        try {
            app(ManagePackages::class)->install('smartmontools');
        } catch (AgentException $e) {
            Toast::error(__('Instalace selhala'), $e->getMessage());
        }
        $this->loadBackgroundJobs();
    }

    protected function headerActions(): array
    {
        $run = fn (callable $call, string $ok) => function (array $data) use ($call, $ok) {
            try {
                $call($data);
                Toast::success(__($ok));
            } catch (AgentException $e) {
                Toast::error(__('Změna selhala'), $e->getMessage());
            }
            $this->load();
        };

        return [
            Action::make('hostname')->label(__('Název serveru'))->icon('outline:pencil-square')->color('gray')
                ->modalDescription(__('Název, pod kterým se server hlásí (hostname), např. web1.example.com. Adresu panelu ani název pro poštu nemění.'))
                ->fillFormUsing(fn () => ['hostname' => $this->settings['hostname'] ?? ''])
                ->form([
                    TextInput::make('hostname')->label(__('Název'))->required()->maxLength(253)
                        ->rules(['regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/']),
                ])
                ->action($run(fn ($d) => app(ManageSystem::class)->setHostname((string) $d['hostname']), 'Název serveru změněn')),
            Action::make('public_address')->label(__('Veřejná adresa'))->icon('outline:globe-alt')->color('gray')
                ->modalDescription(fn () => __('Adresy, na které míří navrhované DNS záznamy webů, pošty a nameserverů. Za NATem (server ve vnitřní síti) je to veřejná adresa routeru, ne adresa rozhraní.')
                    .($this->seen['ipv4'] !== '' ? ' '.__('Internet tento server vidí z :ip.', ['ip' => $this->seen['ipv4']]) : ''))
                ->fillFormUsing(fn () => [
                    'ipv4' => $this->internal && $this->seen['ipv4'] !== '' ? $this->seen['ipv4'] : $this->address['ipv4'],
                    'ipv6' => $this->address['ipv6'],
                ])
                ->form([
                    TextInput::make('ipv4')->label(__('Veřejná IPv4'))->rules(['nullable', 'ipv4']),
                    TextInput::make('ipv6')->label(__('Veřejná IPv6 (nepovinné)'))->rules(['nullable', 'ipv6']),
                ])
                ->action($run(fn ($d) => app(ManagePublicAddress::class)->set((string) ($d['ipv4'] ?? ''), (string) ($d['ipv6'] ?? '')), 'Veřejná adresa nastavena')),
            Action::make('timezone')->label(__('Časové pásmo'))->icon('outline:globe-europe-africa')->color('gray')
                ->modalDescription(__('Podle časového pásma serveru běží naplánované úlohy, automatický restart a časy v logech.'))
                ->fillFormUsing(fn () => ['timezone' => $this->settings['timezone'] ?? 'UTC'])
                ->form(fn () => [
                    Select::make('timezone')->label(__('Časové pásmo'))->searchable()->required()
                        ->options(fn () => collect($this->timezones())->mapWithKeys(fn ($z) => [$z => $z])->all())
                        ->rules([fn () => 'in:'.implode(',', $this->timezones())]),
                ])
                ->action($run(fn ($d) => app(ManageSystem::class)->setTimezone((string) $d['timezone']), 'Časové pásmo změněno')),
            Action::make('swap')->busyWhile('system-swap')->label(__('Swap'))->icon('outline:arrows-right-left')->color('gray')
                ->modalDescription(__('Swapový soubor na disku: když dojde paměť, jádro do něj odloží málo používané stránky místo ukončování procesů. Velikost 0 swapový soubor odstraní. Swappiness (0–200) říká, jak ochotně jádro swapuje; pro servery se hodí 10.'))
                ->fillFormUsing(fn () => [
                    'size_mb' => (int) round(($this->settings['swap_file_size'] ?? 0) / 1048576),
                    'swappiness' => max(0, (int) ($this->settings['swappiness'] ?? 60)),
                ])
                ->form([
                    TextInput::make('size_mb')->label(__('Velikost swapového souboru (MB)'))->integer()->minValue(0)->maxValue(65536)
                        ->rules(['required', 'integer', function (string $attr, mixed $value, \Closure $fail) {
                            if ((int) $value !== 0 && ((int) $value < 256 || (int) $value > 65536)) {
                                $fail(__('Zadejte 0 (bez swapu) nebo 256 až 65536 MB.'));
                            }
                        }]),
                    TextInput::make('swappiness')->label(__('Swappiness'))->integer()->minValue(0)->maxValue(200)->rules(['required', 'integer', 'between:0,200']),
                ])
                // In the background: swapoff and writing a new file of gigabytes take a while.
                ->action(function (array $data) {
                    $current = (int) round(($this->settings['swap_file_size'] ?? 0) / 1048576);
                    $this->queueBackgroundJob('system-swap', ['title' => __('Nastavení swapu'), 'done' => __('Swap nastaven')],
                        ManageSystem::class, 'setSwap', [(int) $data['size_mb'] === $current ? null : (int) $data['size_mb'], (int) $data['swappiness']]);
                }),
        ];
    }

    /** @return list<string> */
    private function timezones(): array
    {
        static $zones = null;
        try {
            return $zones ??= app(ManageSystem::class)->settings(true)['timezones'] ?? [];
        } catch (AgentException) {
            return [];
        }
    }
}
