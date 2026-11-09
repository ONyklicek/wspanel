<?php

namespace App\Livewire\Pages;

use App\Actions\Databases\ManageDatabaseInstances;
use App\Actions\Databases\ManageDatabaseServers;
use App\Enums\DatabaseEngine;
use App\Livewire\Concerns\TranslatesPageText;
use App\Livewire\Concerns\WatchesBackgroundJobs;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use App\Support\Toast;
use App\Wire\Forms\Options;
use App\Wire\Resources\DatabaseResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Checkbox;
use NyonCode\WireForms\Components\Display\Placeholder;
use NyonCode\WireForms\Components\Radio;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\Textarea;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WirePanels\Pages\Page;

/**
 * MariaDB / PostgreSQL as servers: superuser password, access from the
 * internet, and the database tools over every database.
 */
class DatabaseServersPage extends Page
{
    use TranslatesPageText;
    use WatchesBackgroundJobs;

    protected static string $view = 'livewire.pages.database-servers';

    /** Server-wide settings: administrators only. */
    protected static ?string $permission = 'admin';

    protected ?string $title = 'Databázové servery';

    protected static ?string $slug = 'db-servers';

    protected static ?string $navigationLabel = 'Servery';

    protected static ?string $navigationIcon = 'outline:server';

    protected static ?string $navigationParent = DatabaseResource::class;

    protected static int $navigationSort = 25;

    /** @var array<string, array<string, mixed>> */
    public array $servers = [];

    public ?string $error = null;

    /** @var list<array<string, mixed>> the sites' dedicated servers */
    public array $instances = [];

    public function mount(): void
    {
        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        try {
            $this->servers = app(ManageDatabaseServers::class)->status();
            $this->instances = app(ManageDatabaseInstances::class)->list(fresh: true);
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function backgroundJobKeys(): array
    {
        return ['engine-install', 'db-instance'];
    }

    /** The new engine shows here, in the create form and in the services. */
    protected function backgroundJobFinished(string $key, bool $ok): void
    {
        Cache::forget('agent.sys.versions');
        $this->refreshStatus();
    }

    /** Start, stop or restart a dedicated server. */
    public function controlInstance(int $id, string $action): void
    {
        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            return;
        }
        try {
            app(ManageDatabaseInstances::class)->control($id, $action);
            Toast::success(['start' => __('Server spuštěn'), 'stop' => __('Server zastaven'), 'restart' => __('Server restartován')][$action]);
        } catch (AgentException $e) {
            Toast::error(__('Akce selhala'), $e->getMessage());
        }
        $this->refreshStatus();
    }

    public function deleteInstance(int $id): void
    {
        try {
            app(ManageDatabaseInstances::class)->delete($id);
            Toast::success(__('Vyhrazený server smazán'));
        } catch (AgentException $e) {
            Toast::error(__('Vyhrazený server se nepodařilo smazat'), $e->getMessage());
        }
        $this->refreshStatus();
    }

    public function instanceLimitsAction(int $id): Action
    {
        return Action::make('instanceLimits'.$id)
            ->label(__('Limity'))
            ->icon('outline:adjustments-horizontal')
            ->size('sm')
            ->color('gray')
            ->modalDescription(__('Server se po změně restartuje (na pár sekund nebude dostupný).'))
            ->fillFormUsing(function () use ($id) {
                $i = collect($this->instances)->firstWhere('id', $id) ?? [];

                return ['memory_mb' => $i['memory_mb'] ?? 1024, 'cpu_percent' => $i['cpu_percent'] ?? 0];
            })
            ->form([
                TextInput::make('memory_mb')->label(__('Paměť (MB)'))->numeric()->required()->rules(['integer', 'min:256', 'max:262144']),
                TextInput::make('cpu_percent')->label(__('CPU (% jednoho jádra)'))->numeric()->required()->rules(['integer', 'min:0', 'max:6400'])
                    ->helperText(__('0 = bez limitu, 200 = dvě jádra.')),
            ])
            ->action(function (array $data) use ($id) {
                try {
                    app(ManageDatabaseInstances::class)->update($id, (int) $data['memory_mb'], (int) $data['cpu_percent']);
                    Toast::success(__('Limity změněny'));
                } catch (AgentException $e) {
                    Toast::error(__('Změna selhala'), $e->getMessage());
                }
                $this->refreshStatus();
            });
    }

    /** @return array<string, string> engines this server does not have yet, value => label */
    public function missingEngines(): array
    {
        return collect(DatabaseEngine::cases())
            ->reject(fn (DatabaseEngine $e) => in_array($e, app(SystemInfo::class)->dbEngines(), true))
            ->mapWithKeys(fn (DatabaseEngine $e) => [$e->value => $e->getLabel()])->all();
    }

    /** @return array<int, string> active sites that may still get a dedicated server of some engine */
    public function instanceSites(): array
    {
        return Site::query()->where('status', 'active')->orderBy('domain')->pluck('domain', 'id')->all();
    }

    protected function headerActions(): array
    {
        $engines = collect(app(SystemInfo::class)->dbEngines())->filter(fn (DatabaseEngine $e) => $e->supportsDedicated())
            ->mapWithKeys(fn (DatabaseEngine $e) => [$e->value => $e->getLabel()]);

        return [
            Action::make('createInstance')
                ->label(__('Vyhrazený server pro web'))
                ->icon('outline:lock-closed')
                ->color('gray')
                ->visible(fn () => $engines->isNotEmpty() && ! isset($this->backgroundJobs['db-instance']))
                ->modalDescription(__('Web dostane vlastní databázový server: běží pod vlastním uživatelem, s vlastními daty a bez sítě. Připojit se k němu může jen tento web (přes socket). Databáze v něm založíte jako obvykle, s volbou Na vyhrazeném serveru webu.'))
                ->fillFormUsing(fn () => ['engine' => $engines->keys()->first(), 'memory_mb' => 1024, 'cpu_percent' => 100])
                ->form(fn () => [
                    Select::make('site_id')->label(__('Web'))->options($this->instanceSites())->required()->rules([Options::rule()]),
                    Select::make('engine')->label(__('Engine'))->options($engines->all())->required()->rules([Options::rule()]),
                    TextInput::make('memory_mb')->label(__('Paměť (MB)'))->numeric()->required()->rules(['integer', 'min:256', 'max:262144'])
                        ->helperText(__('Strop paměti serveru; polovina připadne na cache dat (MariaDB), čtvrtina na shared_buffers (PostgreSQL).')),
                    TextInput::make('cpu_percent')->label(__('CPU (% jednoho jádra)'))->numeric()->required()->rules(['integer', 'min:0', 'max:6400'])
                        ->helperText(__('0 = bez limitu, 200 = dvě jádra.')),
                ])
                ->action(function (array $data) {
                    $site = Site::find($data['site_id']);
                    $label = DatabaseEngine::from($data['engine'])->getLabel();
                    try {
                        $this->startBackgroundJob('db-instance', app(ManageDatabaseInstances::class)->create($site, $data['engine'], (int) $data['memory_mb'], (int) $data['cpu_percent']), [
                            'title' => __('Vyhrazený :engine pro :domain', ['engine' => $label, 'domain' => $site->domain]),
                            'done' => __('Vyhrazený :engine pro :domain běží', ['engine' => $label, 'domain' => $site->domain]),
                            'body' => __('Databáze v něm založíte v Databáze → Nová databáze.'),
                        ]);
                    } catch (AgentException $e) {
                        Toast::error(__('Vyhrazený server se nepodařilo založit'), $e->getMessage());
                    }
                }),
            Action::make('installEngine')
                ->label(__('Doinstalovat engine'))
                ->icon('outline:plus-circle')
                ->visible(fn () => $this->missingEngines() !== [] && ! isset($this->backgroundJobs['engine-install']))
                ->modalDescription(__('Nainstaluje databázový server a PHP ovladače pro všechny verze PHP. Engine pak z panelu nejde odebrat (odinstalace by smazala data).'))
                ->fillFormUsing(fn () => ['engine' => array_key_first($this->missingEngines())])
                ->form(fn () => [Select::make('engine')->rules([Options::rule()])->label(__('Engine'))->options($this->missingEngines())->required()])
                ->action(function (array $data) {
                    $label = DatabaseEngine::from($data['engine'])->getLabel();
                    try {
                        $this->startBackgroundJob('engine-install', app(AgentClient::class)->call('engine.install', ['engine' => $data['engine']]), [
                            'title' => __('Instalace :engine', ['engine' => $label]),
                            'done' => __(':engine nainstalován', ['engine' => $label]),
                            'body' => __('Nové databáze teď můžete zakládat i v něm; služba je v Server → Služby.'),
                        ]);
                    } catch (AgentException $e) {
                        Toast::error(__('Instalace selhala'), $e->getMessage());
                    }
                }),
        ];
    }

    public static function engineLabel(string $engine): string
    {
        return DatabaseEngine::tryFrom($engine)?->getLabel() ?? $engine;
    }

    /** The address clients use: the panel's host name (it points at this server). */
    public function host(): string
    {
        return (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: config('panel.public_ipv4') ?: 'server');
    }

    /** @return list<string> */
    public function tools(string $engine): array
    {
        $tools = app(SystemInfo::class)->dbTools();

        return array_values(array_filter($tools, fn (string $t) => $t === 'adminer' || $engine === 'mariadb'));
    }

    public function setAt(string $engine): ?string
    {
        $at = $this->servers[$engine]['root_set_at'] ?? null;

        return $at ? Carbon::parse($at)->timezone(config('app.timezone'))->format('j. n. Y H:i') : null;
    }

    /** @return array<string, string> example client commands */
    public function examples(string $engine): array
    {
        $s = $this->servers[$engine] ?? [];
        $host = $this->host();
        $port = (int) ($s['port'] ?? 0);

        return $engine === 'mariadb'
            ? [
                __('Superuživatel') => "mariadb -h {$host} -P {$port} -u root -p --ssl",
                __('Databáze webu') => __('mariadb -h :host -P :port -u <název databáze> -p --ssl <název databáze>', ['host' => $host, 'port' => $port]),
            ]
            : [
                __('Superuživatel') => "psql \"host={$host} port={$port} user=postgres dbname=postgres sslmode=require\"",
                __('Databáze webu') => __('psql "host=:host port=:port user=<název databáze> dbname=<název databáze> sslmode=require"', ['host' => $host, 'port' => $port]),
            ];
    }

    protected function actions(): array
    {
        $actions = [];
        foreach (array_keys($this->servers) as $engine) {
            $actions[] = $this->rootPasswordAction($engine);
            $actions[] = $this->accessAction($engine);
        }
        foreach ($this->instances as $i) {
            $actions[] = $this->instanceLimitsAction((int) $i['id']);
        }

        return $actions;
    }

    public function rootPasswordAction(string $engine): Action
    {
        $label = self::engineLabel($engine);
        $user = $engine === 'mariadb' ? 'root' : 'postgres';
        $hasPassword = (bool) ($this->servers[$engine]['root_password'] ?? false);

        return Action::make("rootPassword_{$engine}")
            ->label($hasPassword ? __('Změnit heslo') : __('Nastavit heslo'))
            ->icon('outline:key')
            ->color('gray')
            ->modalHeading(__('Heslo uživatele :user (:label)', ['user' => $user, 'label' => $label]))
            ->modalDescription(__('Superuživatel :user vidí a smí měnit všechny databáze. Heslo potřebujete pro připojení z vlastního klienta (TablePlus, DBeaver, DataGrip…) nebo z jiného serveru. Panel heslo neukládá; správa serveru na něm nezávisí.', ['user' => $user]))
            ->form([
                Radio::make('mode')->rules([Options::rule()])
                    ->label(__('Heslo'))
                    ->options(array_filter([
                        'generate' => __('Vygenerovat silné heslo'),
                        'own' => __('Zadat vlastní'),
                        'remove' => $hasPassword ? __('Zrušit heslo (jen ze serveru)') : null,
                    ]))
                    ->descriptions(['generate' => __('Zobrazí se jednou po uložení.'), 'remove' => __('Přihlášení :user po síti přestane fungovat.', ['user' => $user])])
                    ->default('generate')
                    ->required()
                    ->live(),
                TextInput::make('password')
                    ->label(__('Nové heslo'))
                    ->password()
                    ->revealable()
                    ->minLength(16)
                    ->maxLength(128)
                    ->rules(['min:16', 'max:128', 'regex:/^[A-Za-z0-9!#$%&()*+,\-.\/:;<=>?@\[\]^_{|}~]+$/'])
                    ->validationMessages(['regex' => __('Písmena, číslice a interpunkce bez uvozovek, zpětného lomítka, backticku a mezer.')])
                    ->helperText(__('Alespoň 16 znaků.'))
                    ->visibleWhen('mode', 'own')
                    ->requiredIf('mode', 'own'),
            ])
            ->modalSubmitActionLabel(__('Uložit'))
            ->action(function (array $data) use ($engine, $user, $label) {
                $servers = app(ManageDatabaseServers::class);
                try {
                    if ($data['mode'] === 'remove') {
                        $servers->removeRootPassword($engine);
                        Toast::success(__('Heslo uživatele :user zrušeno', ['user' => $user]), __('Přihlásit se jde jen ze serveru.'));
                    } else {
                        $generated = $servers->setRootPassword($engine, $data['mode'] === 'own' ? $data['password'] : null);
                        $generated !== null
                            ? Toast::secret(__('Heslo uživatele :user (:label)', ['user' => $user, 'label' => $label]), __('Uživatel :user, heslo (zobrazí se jen teď): :generated', ['user' => $user, 'generated' => $generated]))
                            : Toast::success(__('Heslo uživatele :user nastaveno', ['user' => $user]));
                    }
                } catch (AgentException $e) {
                    Toast::error(__('Heslo se nepodařilo nastavit'), $e->getMessage());
                }
                $this->refreshStatus();
            });
    }

    public function accessAction(string $engine): Action
    {
        $label = self::engineLabel($engine);
        $port = (int) ($this->servers[$engine]['port'] ?? 0);
        $clientIp = request()->ip();

        return Action::make("access_{$engine}")
            ->label(__('Přístup z internetu'))
            ->icon('outline:globe-europe-africa')
            ->modalHeading(__(':label: přístup z internetu', ['label' => $label]))
            ->modalDescription(__('Připojení zvenku jde vždy jen šifrovaně (TLS) a s heslem. Opakovaně chybná přihlášení zablokuje fail2ban. Weby na tomto serveru se připojují lokálně a změna je neovlivní.'))
            ->modalWidth('2xl')
            ->fillFormUsing(fn () => [
                'access' => ManageDatabaseServers::access($this->servers[$engine] ?? []),
                'allow' => implode("\n", $this->servers[$engine]['allow'] ?? []),
            ])
            ->form([
                Radio::make('access')->rules([Options::rule()])
                    ->label(__('Kdo se může připojit'))
                    ->options([
                        ManageDatabaseServers::ACCESS_LOCAL => __('Jen tento server'),
                        ManageDatabaseServers::ACCESS_ALLOWLIST => __('Vybrané adresy'),
                        ManageDatabaseServers::ACCESS_ANYWHERE => __('Kdokoli z internetu'),
                    ])
                    ->descriptions([
                        ManageDatabaseServers::ACCESS_LOCAL => __('Port :port je zavřený. Ze svého počítače se připojíte přes SSH tunel.', ['port' => $port]),
                        ManageDatabaseServers::ACCESS_ALLOWLIST => __('Port :port se otevře jen pro zadané IP adresy a sítě (kancelář, VPN, CI).', ['port' => $port]),
                        ManageDatabaseServers::ACCESS_ANYWHERE => __('Port :port bude otevřený celému internetu a roboti začnou zkoušet hesla. Jen se silnými hesly.', ['port' => $port]),
                    ])
                    ->colors([ManageDatabaseServers::ACCESS_ANYWHERE => 'danger'])
                    ->cards()
                    ->required()
                    ->live(),
                Textarea::make('allow')
                    ->label(__('Povolené adresy'))
                    ->rows(4)
                    ->placeholder("203.0.113.7\n10.8.0.0/24")
                    ->helperText(__('Jedna IP adresa nebo síť (CIDR) na řádek.').($clientIp ? __(' Vaše adresa: :client_ip.', ['client_ip' => $clientIp]) : ''))
                    ->visibleWhen('access', ManageDatabaseServers::ACCESS_ALLOWLIST)
                    ->requiredIf('access', ManageDatabaseServers::ACCESS_ALLOWLIST)
                    ->rules([function (string $attribute, mixed $value, \Closure $fail) {
                        $list = ManageDatabaseServers::parseAllow((string) $value);
                        if (count($list) > 50) {
                            $fail(__('Nejvýš 50 adres.'));
                        }
                        foreach ($list as $entry) {
                            if (! ManageDatabaseServers::validAddress($entry)) {
                                $fail(__(':entry není IP adresa ani síť.', ['entry' => $entry]));

                                return;
                            }
                        }
                    }]),
                Checkbox::make('understood')
                    ->label(__('Rozumím, že :label bude dostupná z celého internetu.', ['label' => $label]))
                    ->visibleWhen('access', ManageDatabaseServers::ACCESS_ANYWHERE)
                    // Hidden (and so not validated) unless "anywhere" is chosen.
                    ->rules(['accepted'])
                    ->validationMessages(['accepted' => __('Potvrďte, že rozumíte riziku.')]),
                Placeholder::make('users')
                    ->label(__('Kdo se přihlásí'))
                    ->content($engine === 'mariadb'
                        ? __('Vlastníci databází (stejné jméno a heslo jako v .env webu) a root, pokud má nastavené heslo.')
                        : __('Vlastníci databází (stejné jméno a heslo jako v .env webu) a postgres, pokud má nastavené heslo.'))
                    ->hiddenWhen('access', ManageDatabaseServers::ACCESS_LOCAL),
            ])
            ->modalSubmitActionLabel(__('Uložit'))
            ->action(function (array $data) use ($engine, $label, $port) {
                try {
                    $out = app(ManageDatabaseServers::class)->setAccess($engine, $data['access'], ManageDatabaseServers::parseAllow($data['allow'] ?? ''));
                    match ($data['access']) {
                        ManageDatabaseServers::ACCESS_LOCAL => Toast::success(__(':label je dostupná jen z tohoto serveru', ['label' => $label]), __('Port :port je zavřený.', ['port' => $port])),
                        ManageDatabaseServers::ACCESS_ANYWHERE => Toast::warning(__(':label je dostupná z internetu', ['label' => $label]), __('Port :port je otevřený všem. Připojení jen přes TLS.', ['port' => $port])),
                        default => Toast::success(__(':label je dostupná z vybraných adres', ['label' => $label]), implode(', ', $out['allow'] ?? [])),
                    };
                    if (isset($out['warning'])) {
                        Toast::warning(__('Ochrana proti hádání hesel'), (string) $out['warning']);
                    }
                } catch (AgentException $e) {
                    Toast::error(__('Nastavení se nepodařilo uložit'), $e->getMessage());
                }
                $this->refreshStatus();
            });
    }
}
