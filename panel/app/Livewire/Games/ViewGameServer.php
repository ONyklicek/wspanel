<?php

namespace App\Livewire\Games;

use App\Actions\Games\ManageGameServers;
use App\Actions\Sites\ManageSsh;
use App\Enums\Game;
use App\Livewire\Concerns\TranslatesPageText;
use App\Models\GameServer;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Resources\GameServerResource;
use Illuminate\Contracts\View\View;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WireCore\Infolists\Infolist;
use NyonCode\WireForms\Components\Radio;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\Textarea;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireForms\Components\Toggle;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

/** One game server: state and players, console, log, backups. */
class ViewGameServer extends ViewPage
{
    use TranslatesPageText;

    protected static ?string $resource = GameServerResource::class;

    /** @var array<string, mixed>|null */
    public ?array $status = null;

    public ?string $error = null;

    public string $command = '';

    /** @var list<array{command: string, output: string}> */
    public array $console = [];

    /** @var list<string>|null */
    public ?array $logs = null;

    /** @var list<array{file: string, size: int, created_at: string}>|null */
    public ?array $backups = null;

    /** @var list<array{file: string, size: int, modified: string}> */
    public array $offsite = [];

    public function mount(mixed $record = null): void
    {
        parent::mount($record);
        $this->refreshStatus();
    }

    public function server(): GameServer
    {
        /** @var GameServer */
        return $this->nativeRecord();
    }

    public function getTitle(): ?string
    {
        return $this->nativeRecord()?->name ?? __('Herní server');
    }

    public function breadcrumbs(): array
    {
        return [
            NavigationItem::make(__('Herní servery'))->url(GameServerResource::url('index')),
            NavigationItem::make((string) $this->getTitle()),
        ];
    }

    public function infolist(): Infolist
    {
        return Infolist::make()->schema([]);
    }

    public function refreshStatus(): void
    {
        try {
            $this->status = app(ManageGameServers::class)->status($this->server());
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function control(string $action): void
    {
        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            return;
        }
        try {
            app(ManageGameServers::class)->control($this->server(), $action);
            Toast::success(match ($action) {
                'start' => __('Server se spouští'),
                'stop' => __('Server zastaven'),
                default => __('Server se restartuje'),
            });
        } catch (AgentException $e) {
            Toast::error(__('Akce selhala'), $e->getMessage());
        }
        $this->refreshStatus();
    }

    public function send(): void
    {
        $this->validate(['command' => ['required', 'string', 'max:1000', 'not_regex:/[\r\n]/']], [], ['command' => __('příkaz')]);
        try {
            $out = app(ManageGameServers::class)->command($this->server(), $this->command);
            $this->console[] = ['command' => $this->command, 'output' => $out];
            $this->console = array_slice($this->console, -30);
            $this->command = '';
        } catch (AgentException $e) {
            Toast::error(__('Příkaz se nepodařilo poslat'), $e->getMessage());
        }
    }

    public function loadLogs(): void
    {
        try {
            $this->logs = app(ManageGameServers::class)->logs($this->server(), 300);
        } catch (AgentException $e) {
            Toast::error(__('Log se nepodařilo načíst'), $e->getMessage());
        }
    }

    public function loadBackups(): void
    {
        try {
            $this->backups = app(ManageGameServers::class)->backups($this->server());
            $this->offsite = app(ManageGameServers::class)->offsiteCopies($this->server());
        } catch (AgentException $e) {
            Toast::error(__('Zálohy se nepodařilo načíst'), $e->getMessage());
        }
    }

    public function backupNow(): void
    {
        try {
            $out = app(ManageGameServers::class)->backup($this->server());
            match (true) {
                $out === null => Toast::warning(__('Záloha ještě běží'), __('Obnovte seznam za chvíli.')),
                isset($out['offsite_error']) => Toast::warning(__('Záloha vytvořena, ale kopie mimo server selhala'), $out['offsite_error']),
                isset($out['offsite']) => Toast::success(__('Záloha vytvořena a zkopírována mimo server'), $out['file']),
                default => Toast::success(__('Záloha vytvořena'), $out['file']),
            };
        } catch (AgentException $e) {
            Toast::error(__('Záloha selhala'), $e->getMessage());
        }
        $this->loadBackups();
    }

    public function fetchOffsite(string $file): void
    {
        try {
            app(ManageGameServers::class)->fetchOffsite($this->server(), $file);
            Toast::success(__('Kopie stažena zpět'), __('Je v seznamu záloh, odtud ji obnovíte.'));
        } catch (AgentException $e) {
            Toast::error(__('Stažení selhalo'), $e->getMessage());
        }
        $this->loadBackups();
    }

    public function restore(string $file): void
    {
        try {
            app(ManageGameServers::class)->restore($this->server(), $file)
                ? Toast::success(__('Obnoveno ze zálohy :file', ['file' => $file]))
                : Toast::warning(__('Obnova ještě běží'), __('Obnovte stránku za chvíli.'));
        } catch (AgentException $e) {
            Toast::error(__('Obnova selhala'), $e->getMessage());
        }
        $this->refreshStatus();
    }

    protected function headerActions(): array
    {
        $server = fn () => $this->server();

        return [
            Action::make('settings')->label(__('Nastavení'))->icon('outline:cog-6-tooth')->color('gray')
                ->modalDescription(__('Nová paměť a procesor platí po restartu serveru (běžící server se restartuje sám).'))
                ->fillFormUsing(fn () => ['name' => $server()->name, 'memory_mb' => $server()->memory_mb, 'cpu_percent' => $server()->cpu_percent])
                ->form([
                    TextInput::make('name')->label(__('Název'))->required()->maxLength(64)->rules(['max:64', 'regex:/^[^"\\\\\x00-\x1f]+$/']),
                    TextInput::make('memory_mb')->label(__('Paměť (MB)'))->integer()->required()->rules(['required', 'integer', 'between:1024,262144']),
                    TextInput::make('cpu_percent')->label(__('Procesor (% jednoho jádra, 0 = bez omezení)'))->integer()->rules(['nullable', 'integer', 'between:0,6400']),
                ])
                ->action(function (array $data) use ($server) {
                    try {
                        app(ManageGameServers::class)->updateSettings($server(), $data);
                        Toast::success(__('Nastavení uloženo'));
                    } catch (AgentException $e) {
                        Toast::error(__('Nastavení se nepodařilo uložit'), $e->getMessage());
                    }
                    $this->refreshStatus();
                }),
            Action::make('sftp')->label('SFTP')->icon('outline:folder-arrow-down')->color('gray')
                ->modalHeading(fn () => __('SFTP pro :name', ['name' => $server()->name]))
                ->modalDescription(fn () => __('Přihlášení jako :user (port :port) do složky serveru; jen přenos souborů, žádný shell. Opakovaně chybná přihlášení zablokuje fail2ban.', ['user' => $server()->systemUser(), 'port' => config('panel.ssh_port')]))
                ->modalWidth('2xl')
                ->fillFormUsing(fn () => ['enabled' => $server()->sftp, 'keys' => (string) $server()->sftp_keys, 'password_mode' => 'keep'])
                ->form(fn () => [
                    Toggle::make('enabled')->label(__('Povolit SFTP'))->live(),
                    Radio::make('password_mode')->label(__('Heslo'))->required()->live()
                        ->options($server()->sftp_password
                            ? ['keep' => __('Ponechat současné'), 'generate' => __('Vygenerovat nové'), 'set' => __('Zadat vlastní'), 'remove' => __('Zrušit (jen klíče)')]
                            : ['keep' => __('Bez hesla (jen klíče)'), 'generate' => __('Vygenerovat heslo'), 'set' => __('Zadat vlastní')])
                        ->rules(['in:keep,generate,set,remove'])
                        ->visible(fn ($get) => (bool) $get('enabled')),
                    TextInput::make('password')->label(__('Nové heslo'))->password()->revealable()->minLength(12)->maxLength(128)
                        ->rules(['min:12', 'max:128', 'regex:/^[\x20-\x39\x3b-\x7e]+$/'])
                        ->validationMessages(['regex' => __('Jen tisknutelné ASCII znaky bez dvojtečky.')])
                        ->visible(fn ($get) => $get('enabled') && $get('password_mode') === 'set')->requiredIf('password_mode', 'set'),
                    Textarea::make('keys')->label(__('Veřejné klíče'))->rows(4)->placeholder(__('ssh-ed25519 AAAA… jmeno@pocitac'))
                        ->helperText(__('Jeden na řádek. Klíč je bezpečnější než heslo.'))
                        ->visible(fn ($get) => (bool) $get('enabled'))
                        ->required(fn ($get) => $get('enabled') && ! in_array($get('password_mode') ?? 'keep', ['generate', 'set'], true)
                            && ! ($server()->sftp_password && ($get('password_mode') ?? 'keep') === 'keep'))
                        ->rules([function (string $attribute, mixed $value, \Closure $fail) {
                            if (trim((string) $value) !== '' && ManageSsh::parseKeys((string) $value) === []) {
                                $fail(__('Nenašel jsem žádný platný klíč.'));
                            }
                        }])
                        ->validationMessages(['required' => __('Zadejte veřejný klíč, nebo nastavte heslo.')]),
                ])
                ->action(function (array $data) use ($server) {
                    try {
                        $generated = app(ManageGameServers::class)->setSftp($server(), (bool) $data['enabled'], (string) ($data['keys'] ?? ''), (string) ($data['password_mode'] ?? 'keep'), $data['password'] ?? null);
                        $login = 'sftp -P '.config('panel.ssh_port').' '.$server()->sftpTarget();
                        match (true) {
                            ! $data['enabled'] => Toast::success(__('SFTP vypnuto')),
                            $generated !== null => Toast::secret(__('SFTP zapnuto'), __(':login
Heslo (zobrazí se jen teď): :generated', ['login' => $login, 'generated' => $generated])),
                            default => Toast::success(__('SFTP zapnuto'), $login),
                        };
                    } catch (AgentException $e) {
                        Toast::error(__('Nastavení SFTP selhalo'), $e->getMessage());
                    }
                }),
            Action::make('upgrade')->label(__('Aktualizovat'))->icon('outline:arrow-path')->color('gray')
                ->modalDescription(fn () => $server()->game === Game::Minecraft
                    ? __('Stáhne nejnovější sestavení zvolené verze (nebo novější verzi). Server se na chvíli zastaví. Svět se na novější verzi převede a zpět už nejde: zálohu udělejte předem.')
                    : __('Stáhne nejnovější stabilní Factorio. Server se na chvíli zastaví, mapa zůstane.'))
                ->form(fn () => $server()->game === Game::Minecraft ? [
                    Select::make('version')->label(__('Verze'))->required()
                        ->options(collect(app(ManageGameServers::class)->versions($server()->game, $server()->flavor))
                            ->filter(fn ($v) => version_compare($v, $server()->version, '>='))->mapWithKeys(fn ($v) => [$v => $v])->all())
                        ->default($server()->version),
                ] : [])
                ->action(function (array $data) use ($server) {
                    try {
                        $out = app(ManageGameServers::class)->upgrade($server(), $data['version'] ?? null);
                        match (true) {
                            $out === null => Toast::warning(__('Aktualizace ještě běží'), __('Obnovte stránku za chvíli.')),
                            ! ($out['changed'] ?? false) => Toast::success(__('Server je aktuální')),
                            default => Toast::success(__('Aktualizováno na :version', ['version' => $server()->fresh()->versionLabel()])),
                        };
                    } catch (AgentException $e) {
                        Toast::error(__('Aktualizace selhala'), $e->getMessage());
                    }
                    $this->refreshStatus();
                }),
            Action::make('delete')->label(__('Smazat'))->icon('outline:trash')->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('Server se zastaví a smaže i se světem a zálohami. Port se ve firewallu zavře.'))
                ->action(function () use ($server) {
                    try {
                        app(ManageGameServers::class)->delete($server());
                        Toast::success(__('Herní server smazán'));
                        $this->redirect(GameServerResource::url('index'));
                    } catch (AgentException $e) {
                        Toast::error(__('Smazání selhalo'), $e->getMessage());
                    }
                }),
        ];
    }

    public function render(): View
    {
        return view('livewire.games.view', [
            'title' => $this->getTitle(),
            'breadcrumbs' => $this->breadcrumbs(),
            'headerActions' => $this->renderedHeaderActions(),
            'subNavigation' => $this->subNavigation($this->nativeRecord()),
            'server' => $this->server(),
        ]);
    }
}
