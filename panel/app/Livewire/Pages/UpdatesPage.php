<?php

namespace App\Livewire\Pages;

use App\Actions\System\ManageSystem;
use App\Actions\System\ManageUpdates;
use App\Livewire\Concerns\TranslatesPageText;
use App\Livewire\Concerns\WatchesBackgroundJobs;
use App\Services\Agent\AgentException;
use App\Support\Lang;
use App\Support\PanelRelease;
use App\Support\Toast;
use Illuminate\Support\Carbon;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Radio;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireForms\Components\Toggle;
use NyonCode\WirePanels\Pages\Page;

/** System updates, automatic security updates and restarting the server. */
class UpdatesPage extends Page
{
    use TranslatesPageText;
    use WatchesBackgroundJobs;

    protected static string $view = 'livewire.pages.updates';

    protected static ?string $permission = 'admin';

    protected ?string $title = 'Aktualizace systému';

    protected static ?string $slug = 'updates';

    protected static ?string $navigationLabel = 'Aktualizace';

    protected static ?string $navigationIcon = 'outline:arrow-path-rounded-square';

    protected static ?string $navigationGroup = 'security';

    protected static int $navigationSort = 30;

    /** @var array<string, mixed>|null */
    public ?array $updates = null;

    public ?string $error = null;

    /** @var list<string> the packages ticked in the list */
    public array $selected = [];

    /** Show only security updates in the list. */
    public bool $securityOnly = false;

    public function mount(): void
    {
        $this->load();
        $this->loadUpgrade();
    }

    public function load(bool $refresh = false): void
    {
        try {
            $this->updates = app(ManageUpdates::class)->status($refresh);
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function backgroundJobKeys(): array
    {
        return ['sys-upgrade'];
    }

    protected function backgroundJobFinished(string $key, bool $ok): void
    {
        $this->selected = [];
        $this->load(true);
    }

    /** Whether an upgrade runs now (the buttons wait for it). */
    public function upgrading(): bool
    {
        return isset($this->backgroundJobs['sys-upgrade']);
    }

    /** @return list<string> names of the packages that have an update */
    private function upgradableNames(): array
    {
        return array_values(array_map(fn (array $p) => (string) $p['name'], $this->updates['packages'] ?? []));
    }

    /**
     * Starts an upgrade in the background: 'all', 'security' or the given
     * packages (only those in the list of updates are taken).
     *
     * @param  list<string>  $packages
     */
    public function upgradeNow(string $what = 'packages', array $packages = []): void
    {
        if ($this->upgrading()) {
            Toast::warning(__('Aktualizace už běží'), __('Počkejte, až doběhne.'));

            return;
        }
        $packages = array_values(array_intersect($this->upgradableNames(), $packages));
        if ($what === 'packages' && $packages === []) {
            Toast::warning(__('Nic nevybráno'), __('Zaškrtněte balíčky, které se mají aktualizovat.'));

            return;
        }
        $title = match ($what) {
            'all' => __('Instalace všech aktualizací'),
            'security' => __('Instalace bezpečnostních aktualizací'),
            default => count($packages) === 1 ? __('Aktualizace :name', ['name' => $packages[0]])
                : Lang::count(count($packages), 'Aktualizace :count balíčku', 'Aktualizace :count balíčků', 'Aktualizace :count balíčků'),
        };
        try {
            $job = app(ManageUpdates::class)->upgrade($what === 'security', $what === 'packages' ? $packages : []);
            $this->startBackgroundJob('sys-upgrade', $job, ['title' => $title, 'done' => __('Aktualizace nainstalovány'), 'body' => null]);
        } catch (AgentException $e) {
            Toast::error(__('Aktualizace selhala'), $e->getMessage());
        }
    }

    public function upgradeOne(string $name): void
    {
        $this->upgradeNow('packages', [$name]);
    }

    public function upgradeSelected(): void
    {
        $this->upgradeNow('packages', $this->selected);
    }

    /** Ticks every shown package, or none when all are ticked. */
    public function toggleAll(): void
    {
        $shown = array_column($this->shownPackages(), 'name');
        $this->selected = array_diff($shown, $this->selected) === [] ? [] : $shown;
    }

    /** @return list<array{name: string, from: string, to: string, security: bool}> security ones first */
    public function shownPackages(): array
    {
        return collect($this->updates['packages'] ?? [])
            ->when($this->securityOnly, fn ($c) => $c->where('security', true))
            ->sortBy([['security', 'desc'], ['name', 'asc']])->values()->all();
    }

    public function refresh(): void
    {
        $this->load(true);
        Toast::success(__('Seznam balíčků obnoven'));
    }

    /** @var array<string, mixed>|null the panel's own upgrade (agent's panel.upgrade_status) */
    public ?array $upgrade = null;

    /** Upgrades WSPanel to the newer release (the agent runs it as its own unit). */
    public function upgradePanel(): void
    {
        $release = PanelRelease::available();
        if ($release === null) {
            Toast::warning(__('Není na co aktualizovat'), __('Tento server má nejnovější verzi.'));

            return;
        }
        try {
            app(ManageUpdates::class)->upgradePanel($release['version']);
            Toast::success(__('Aktualizace na :version začala', ['version' => $release['version']]), __('Panel bude pár minut nedostupný, stránka se po aktualizaci obnoví sama.'));
        } catch (AgentException $e) {
            Toast::error(__('Aktualizaci se nepodařilo spustit'), $e->getMessage());
        }
        $this->loadUpgrade();
    }

    public function loadUpgrade(): void
    {
        try {
            $this->upgrade = app(ManageUpdates::class)->panelUpgradeStatus();
        } catch (AgentException) {
            $this->upgrade = null;
        }
        // A failed upgrade to a version this server already has (or passed,
        // e.g. upgraded from the command line since) is history, not news.
        $current = PanelRelease::current();
        if (($this->upgrade['state'] ?? null) === 'failed' && $current !== null && version_compare($current, (string) ($this->upgrade['version'] ?? ''), '>=')) {
            $this->upgrade = null;
        }
        if (isset($this->upgrade['log'])) {
            $this->upgrade['log'] = self::readableLog($this->upgrade['log']);
        }
    }

    /**
     * The upgrade's log without terminal colours and download progress bars.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    public static function readableLog(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $line = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $line);
            if (trim($line) === '' || preg_match('/^[\s#=O.\-]*(\d{1,3}\.\d%)?\s*$/', $line)) {
                continue;
            }
            $out[] = $line;
        }

        return $out;
    }

    /** Asks GitHub for the newest WSPanel now (otherwise daily). */
    public function checkRelease(): void
    {
        $out = PanelRelease::check();
        $out['error'] !== null
            ? Toast::error(__('Novou verzi se nepodařilo zjistit'), $out['error'])
            : (PanelRelease::available() !== null
                ? Toast::success(__('K dispozici je WSPanel :version', ['version' => $out['version']]))
                : Toast::success(__('WSPanel je aktuální')));
    }

    public function toggleAuto(): void
    {
        try {
            $on = ! ($this->updates['auto_security'] ?? false);
            app(ManageUpdates::class)->setAutoSecurity($on);
            Toast::success($on ? __('Bezpečnostní aktualizace se instalují samy') : __('Automatické aktualizace vypnuty'));
        } catch (AgentException $e) {
            Toast::error(__('Změna selhala'), $e->getMessage());
        }
        $this->load();
    }

    public function cancelReboot(): void
    {
        try {
            app(ManageSystem::class)->cancelReboot();
            Toast::success(__('Restart serveru zrušen'));
        } catch (AgentException $e) {
            Toast::error(__('Zrušení selhalo'), $e->getMessage());
        }
        $this->load();
    }

    /** "04:00" style time of a restart, in the panel's time zone. */
    public static function at(?string $iso): string
    {
        return $iso ? Carbon::parse($iso)->setTimezone(config('app.timezone'))->isoFormat('D. M. HH:mm') : '';
    }

    protected function headerActions(): array
    {
        $time = fn (string $name) => TextInput::make($name)->label(__('Čas (čas serveru)'))->placeholder('04:00')
            ->rules(['required', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'])->maxLength(5);

        return [
            Action::make('reboot')->label(__('Restartovat server'))->icon('outline:power')->color(fn () => ($this->updates['reboot_required'] ?? false) ? 'warning' : 'gray')
                ->visible(fn () => empty($this->updates['reboot_scheduled']))
                ->modalDescription(__('Weby, pošta a panel budou během restartu nedostupné, obvykle minutu až dvě. Přihlášení uživatelé (SSH) dostanou upozornění.'))
                ->form([
                    Radio::make('when')->label(__('Kdy'))->options(['now' => __('Za minutu'), 'at' => __('V určený čas (např. v noci)')])->default('now')->required()->live(),
                    $time('at')->visible(fn ($get) => $get('when') === 'at'),
                ])
                ->action(function (array $data) {
                    try {
                        $when = app(ManageSystem::class)->reboot(1, $data['when'] === 'at' ? (string) $data['at'] : null);
                        Toast::success(__('Restart serveru naplánován'), __('Server se restartuje :when.', ['when' => self::at($when)]));
                    } catch (AgentException $e) {
                        Toast::error(__('Restart se nepodařilo naplánovat'), $e->getMessage());
                    }
                    $this->load();
                }),
            Action::make('autoReboot')->label(__('Automatický restart'))->icon('outline:clock')->color('gray')
                ->modalDescription(__('Když automatické bezpečnostní aktualizace přinesou nové jádro nebo knihovny, které se projeví až po restartu, server se restartuje sám v nastavený čas.'))
                ->fillFormUsing(fn () => ['enabled' => (bool) ($this->updates['auto_reboot'] ?? false), 'time' => ($this->updates['auto_reboot_time'] ?? '') ?: '04:00'])
                ->form([
                    Toggle::make('enabled')->label(__('Restartovat automaticky, když je to potřeba'))->live(),
                    $time('time')->visible(fn ($get) => (bool) $get('enabled')),
                ])
                ->action(function (array $data) {
                    try {
                        app(ManageSystem::class)->setAutoReboot((bool) $data['enabled'], $data['enabled'] ? (string) $data['time'] : null);
                        Toast::success($data['enabled'] ? __('Server se po aktualizacích restartuje sám v :time', ['time' => $data['time']]) : __('Automatický restart vypnut'));
                    } catch (AgentException $e) {
                        Toast::error(__('Změna selhala'), $e->getMessage());
                    }
                    $this->load();
                }),
            Action::make('upgradeSecurity')->label(__('Nainstalovat bezpečnostní'))->icon('outline:shield-check')
                ->visible(fn () => ! $this->upgrading() && collect($this->updates['packages'] ?? [])->contains('security', true))
                ->requiresConfirmation()->modalDescription(__('Nainstaluje jen bezpečnostní aktualizace (unattended-upgrades). Služby se po aktualizaci samy restartují.'))
                ->action(fn () => $this->upgradeNow('security')),
            Action::make('upgradeAll')->label(__('Nainstalovat vše'))->icon('outline:arrow-down-tray')->color('gray')
                ->visible(fn () => ! $this->upgrading() && ! empty($this->updates['packages']))
                ->requiresConfirmation()->modalDescription(__('Nainstaluje všechny aktualizace. Konfigurační soubory, které panel upravil, zůstanou (nové výchozí se nepoužijí).'))
                ->action(fn () => $this->upgradeNow('all')),
        ];
    }
}
