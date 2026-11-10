<?php

namespace App\Livewire\Sites;

use App\Actions\Sites\ManageCiToken;
use App\Actions\Sites\ManageDeploys;
use App\Actions\Sites\ManageGitHub;
use App\Actions\Sites\ManageWebhook;
use App\Enums\Framework;
use App\Enums\SiteMode;
use App\Forms\Components\CodeIde;
use App\Livewire\Pages\IntegrationsPage;
use App\Livewire\Sites\Concerns\IsSiteTab;
use App\Models\Deployment;
use App\Models\GithubConnection;
use App\Models\Site;
use App\Models\WebhookDelivery;
use App\Services\Agent\AgentException;
use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\GitHubException;
use App\Support\ConsoleText;
use App\Support\Lang;
use App\Support\PublicAddress;
use App\Support\Toast;
use App\Wire\Actions\Action;
use App\Wire\Forms\Options;
use App\Wire\Resources\SiteResource;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use NyonCode\WireCore\Actions\ActionGroup;
use NyonCode\WireCore\Actions\ModalFooterAction;
use NyonCode\WireForms\Components\Display\Placeholder;
use NyonCode\WireForms\Components\Radio;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\Textarea;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireForms\Components\Toggle;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

/**
 * Deploy of one site: what it does, settings, automation (webhook, polling,
 * CI), history with the live log, rollback and the shared .env.
 */
class SiteDeploy extends ViewPage
{
    protected static ?string $resource = SiteResource::class;

    /** @var array<string, mixed>|null */
    public ?array $config = null;

    public string $current = '';

    /** @var list<string> */
    public array $releases = [];

    public bool $running = false;

    public bool $opcacheProduction = false;

    public ?string $logRelease = null;

    /** How much of the log the browser already has (the log itself never rides the snapshot). */
    public int $logOffset = 0;

    /** The log read on mount, drawn once into the page; later output arrives as a "deploy-log" event. */
    protected string $initialLog = '';

    public string $logStatus = '';

    public const TRIGGERS = [
        'branch' => 'Push do větve (webhook)',
        'tags' => 'Nový release nebo tag (webhook)',
        'poll_branch' => 'Server sám kontroluje větev každou minutu',
        'poll_tags' => 'Server sám kontroluje nové verze (tagy) každou minutu',
    ];

    public function mount(mixed $record = null): void
    {
        parent::mount($record);
        $this->refreshHistory();
        $this->logRelease = $this->deployments()->first()?->release;
        if ($this->logRelease) {
            $this->initialLog = $this->readLog() ?? '';
        }
    }

    public function site(): Site
    {
        /** @var Site */
        return $this->nativeRecord();
    }

    use IsSiteTab;

    protected function tabLabel(): string
    {
        return 'Deploy';
    }

    /** @return Collection<int, Deployment> */
    public function deployments(): Collection
    {
        return Deployment::query()->where('site_id', $this->site()->id)->latest('started_at')->limit(20)->get();
    }

    /** @return Collection<int, WebhookDelivery> */
    public function deliveries(): Collection
    {
        return WebhookDelivery::query()->where('site_id', $this->site()->id)->latest('id')->limit(10)->get();
    }

    public function refreshHistory(): void
    {
        try {
            $h = app(ManageDeploys::class)->history($this->site());
            $this->config = $h['config'];
            $this->current = (string) $h['current'];
            $this->releases = $h['releases'];
            $this->running = (bool) $h['running'];
            $this->opcacheProduction = (bool) ($h['opcache_production'] ?? false);
        } catch (AgentException $e) {
            Toast::error(__('Agent není dostupný'), $e->getMessage());
        }
    }

    /**
     * Polled while a deploy runs: only the new output goes to the browser,
     * which appends it. A composer install logs hundreds of lines; carried
     * in the component state it went up and down whole every two seconds.
     */
    public function refreshLog(): void
    {
        $chunk = $this->readLog();
        if ($chunk !== null && $chunk !== '') {
            $this->dispatch('deploy-log', content: $chunk, reset: false);
        }
        $this->skipRenderUnlessFinished();
    }

    public function showLog(string $release): void
    {
        abort_unless(preg_match('/^\d{8}T\d{6}Z$/', $release) === 1, 404);
        $this->logRelease = $release;
        $this->logOffset = 0;
        $this->dispatch('deploy-log', content: $this->readLog() ?? '', reset: true);
    }

    /** The log's next part from the agent (null when it cannot be read); moves the offset. */
    private function readLog(): ?string
    {
        if (! $this->logRelease) {
            return null;
        }
        try {
            $chunk = app(ManageDeploys::class)->log($this->site(), $this->logRelease, $this->logOffset);
        } catch (AgentException) {
            return null;
        }
        $this->logOffset = $chunk['offset'];
        $this->logStatus = $chunk['status'];
        if ($this->logStatus !== 'running' && $this->running) {
            $this->refreshHistory();
        }

        return ConsoleText::tidy($chunk['content']);
    }

    /** A poll that only appended output changes nothing else on the page. */
    private function skipRenderUnlessFinished(): void
    {
        if ($this->logStatus === 'running' && $this->running) {
            $this->skipRender();
        }
    }

    /**
     * What a deploy of this site does, step by step (mirrors the agent's pipeline).
     *
     * @return list<array{0: string, 1: string}>
     */
    public function pipeline(): array
    {
        $site = $this->site();
        $f = $site->framework;
        $c = $this->config ?? ['migrate' => true, 'assets' => true, 'auto_rollback' => true, 'branch' => 'main', 'keep' => 5];
        $steps = [
            [__('Stažení kódu'), __('git clone větve ').($c['branch'] ?? 'main').__(' (nebo tagu či commitu) do nové složky releases/<čas>, případně rozbalení hotového balíku z CI. Běžící web se zatím nemění.')],
            [__('Sdílené soubory'), match (true) {
                $f === Framework::Laravel => __('Do releasu se nalinkují .env a storage/ ze shared/, takže data a nahrané soubory přežívají deploye.'),
                $f === Framework::Symfony => __('Do releasu se nalinkují .env.local a var/log ze shared/.'),
                default => __('Do releasu se nalinkuje .env ze shared/.'),
            }.__(' Přinese-li release vlastní .env (build z CI), jeho hodnoty se do shared/ doplní').(($c['env_from_deploy'] ?? false) ? __(' a mají přednost.') : __('; klíče, které nastavil panel, zůstanou.'))],
        ];
        if (! $f->isNode() && ! $f->isPython()) {
            $steps[] = ['Composer', __('composer install --no-dev, pokud repozitář obsahuje composer.json (u balíku z CI se přeskočí).')];
        }
        if ($f->isNode() || ($c['assets'] ?? false)) {
            $steps[] = [__('Build assetů'), __('npm ci a npm run build (Vite, Nuxt, Next…), pokud je v repozitáři package.json.')];
        }
        if ($f->isPython()) {
            $steps[] = [__('Závislosti Pythonu'), __('uv vytvoří virtualenv .venv přímo v releasu a nainstaluje závislosti z uv.lock, pyproject.toml nebo requirements.txt. Chybí-li ').($f === Framework::FastApi ? 'uvicorn' : 'gunicorn').__(', doinstaluje se.')];
        }
        if ($f === Framework::Django) {
            if ($c['migrate'] ?? false) {
                $steps[] = [__('Migrace'), 'python manage.py migrate --noinput.'];
            }
            if ($c['assets'] ?? false) {
                $steps[] = [__('Statické soubory'), 'python manage.py collectstatic --noinput'.(($c['commands'] ?? []) !== [] ? __(', pak vlastní příkazy: ').implode(', ', $c['commands']) : '').'.'];
            }
        } elseif ($f->isPython() && ($c['migrate'] ?? false)) {
            $steps[] = [__('Migrace'), __('alembic upgrade head, pokud má repozitář alembic.ini.')];
        }
        if ($f === Framework::Laravel) {
            if ($c['migrate'] ?? false) {
                $steps[] = [__('Migrace'), 'php artisan migrate --force.'];
            }
            $steps[] = [__('Optimalizace'), 'storage:link a artisan optimize'.(($c['commands'] ?? []) !== [] ? __(', pak vlastní příkazy: ').implode(', ', $c['commands']) : '').'.'];
        }
        if ($f === Framework::Symfony) {
            if ($c['migrate'] ?? false) {
                $steps[] = [__('Migrace'), 'doctrine:migrations:migrate.'];
            }
            $steps[] = ['Cache', 'asset-map:compile a cache:warmup pro prod.'];
        }
        $steps[] = [__('Přepnutí'), __('current/ se atomicky přepne na nový release. ').($site->mode === SiteMode::Classic ? __('PHP-FPM se plynule znovu načte.') : __('Proces aplikace se restartuje.')).__(' Workery se restartují s novým kódem.')];
        $steps[] = [__('Kontrola'), ($c['auto_rollback'] ?? false)
            ? __('Panel ověří, že web odpovídá. Když ne, vrátí předchozí release.')
            : __('Panel ověří, že web odpovídá (automatický rollback je vypnutý).')];
        $steps[] = [__('Úklid'), __('Zůstane posledních ').($c['keep'] ?? 5).__(' releasů pro okamžitý rollback.')];

        return $steps;
    }

    protected function headerActions(): array
    {
        return [
            Action::make('deploy')
                ->label(__('Deploy'))
                ->icon('outline:rocket-launch')
                ->disabled(fn () => ($this->config['repo'] ?? '') === '' || $this->running)
                ->requiresConfirmation()
                ->modalHeading(__('Spustit deploy?'))
                ->modalDescription(fn () => __('Nasadí větev ').($this->config['branch'] ?? '?').__('. Build běží pod uživatelem webu; web se přepne až po úspěšném buildu.'))
                ->modalSubmitActionLabel(__('Nasadit'))
                ->action(function () {
                    try {
                        $release = app(ManageDeploys::class)->start($this->site());
                        $this->running = true;
                        if ($release) {
                            $this->showLog($release);
                        }
                    } catch (AgentException $e) {
                        Toast::error(__('Deploy nelze spustit'), $e->getMessage());
                    }
                }),
            $this->githubConnectAction(),
            $this->githubAction(),
            $this->settingsAction(),
            $this->automationAction(),
            ActionGroup::make([
                $this->ciAction(),
                $this->deployKeyAction(),
                $this->envAction(),
                $this->rollbackAction(),
            ])->label(__('Další'))->icon('outline:ellipsis-vertical'),
        ];
    }

    /**
     * Links the site to a GitHub repository through a connected account:
     * deploy key, deploy settings, webhook (or watching) and optionally the
     * CI token as an Actions secret, all over the GitHub API.
     */
    private function githubAction(): Action
    {
        return Action::make('github')
            ->visible(fn () => GithubConnection::query()->exists())
            ->label(fn () => $this->site()->github_repo ?? 'GitHub')
            ->icon('outline:link')
            ->color(fn () => $this->site()->github_repo ? 'success' : 'gray')
            ->modalHeading(fn () => $this->site()->github_repo ? __('Napojeno na GitHub') : __('Napojit na GitHub'))
            ->modalWidth('3xl')
            ->modalSubmitActionLabel(__('Napojit'))
            ->fillFormUsing(fn () => [
                'connection' => $this->site()->github_connection_id ?? GithubConnection::query()->value('id'),
                'repo' => $this->site()->github_repo,
                'branch' => $this->config['branch'] ?? null,
                'trigger' => in_array($this->site()->deploy_trigger, ['tags', 'poll_tags'], true) ? 'tags' : 'branch',
                'notify' => ManageWebhook::reachable($this->site()) ? 'webhook' : 'poll',
                'ci_secret' => false,
            ])
            ->form(fn () => [
                Placeholder::make('linked')->hiddenLabel()
                    ->content(fn () => __('Repozitář :repo (účet @:login). Deploy klíč a :hook vytvořil panel; deploye se v GitHubu ukazují v prostředí :env.', [
                        'repo' => $this->site()->github_repo,
                        'login' => $this->site()->githubConnection?->login ?? '?',
                        'hook' => $this->site()->github_hook_id ? __('webhook') : __('sledování repozitáře'),
                        'env' => $this->site()->github_environment ?? ManageGitHub::ENVIRONMENT,
                    ]))
                    ->visible($this->site()->github_repo !== null),
                Select::make('connection')->label(__('Účet GitHub'))
                    ->options(fn () => GithubConnection::query()->pluck('login', 'id')->map(fn ($l) => '@'.$l)->all())
                    ->required()->live()
                    ->rules([fn () => 'in:'.GithubConnection::query()->pluck('id')->join(',')]),
                Select::make('repo')->label(__('Repozitář'))
                    ->options(fn ($get) => $this->githubOptions(fn (ManageGitHub $gh, GithubConnection $c) => $gh->repositories($c), $get('connection')))
                    ->searchable()->required()->live()
                    ->helperText(__('Repozitáře, které na GitHubu spravujete a ke kterým má panel přístup.'))
                    ->afterStateUpdated(fn ($set) => $set('branch', null)),
                Select::make('branch')->label(__('Větev'))
                    ->options(fn ($get) => $get('repo') ? $this->githubOptions(fn (ManageGitHub $gh, GithubConnection $c) => $gh->branches($c, (string) $get('repo')), $get('connection')) : [])
                    ->searchable()->required()
                    ->rules(['regex:~^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$~']),
                Radio::make('trigger')->label(__('Co nasadit'))->rules([Options::rule()])
                    ->options(['branch' => __('Každý push do větve'), 'tags' => __('Nový release nebo tag')])
                    ->required(),
                // GitHub refuses a webhook to an address it cannot reach
                // (panel.localhost, *.lan, private IPs): only watching is offered then.
                Radio::make('notify')->label(__('Jak se panel o změně dozví'))->rules([Options::rule()])
                    ->options(ManageWebhook::reachable($this->site())
                        ? ['webhook' => __('Webhook (GitHub zavolá panel)'), 'poll' => __('Panel se každou minutu podívá do repozitáře')]
                        : ['poll' => __('Panel se každou minutu podívá do repozitáře')])
                    ->descriptions([
                        'webhook' => ManageWebhook::panelReachable()
                            ? __('Okamžité. GitHub volá panel na :url.', ['url' => rtrim((string) config('app.url'), '/')])
                            : __('Okamžité. Panel z internetu dostupný není, GitHub proto volá veřejnou doménu webu (:domain); její edge předá jen tento podepsaný požadavek panelu.', ['domain' => $this->site()->domain]),
                        'poll' => ManageWebhook::reachable($this->site())
                            ? __('Nic nemusí volat dovnitř; změnu panel zjistí do minuty.')
                            : __('Panel ani web :domain nejsou z internetu dostupné, takže webhook nejde. Změnu panel zjistí do minuty.', ['domain' => $this->site()->domain]),
                    ])
                    ->required(),
                Toggle::make('ci_secret')->label(__('Uložit CI token do secretů repozitáře (WSPANEL_TOKEN)'))
                    ->helperText(__('Pro deploy hotového buildu z GitHub Actions. Vytvoří nový CI token; starý přestane platit.')),
            ])
            ->modalFooterActions($this->site()->github_repo === null ? [] : [
                ModalFooterAction::make('unlinkGithub')
                    ->label(__('Odpojit'))
                    ->icon('outline:link-slash')
                    ->color('danger')
                    ->position('before')
                    ->confirm(__('Panel z repozitáře odebere svůj webhook a deploy klíč. Nastavení deploye zůstane, automatický deploy přes webhook se vypne.'))
                    ->closesModal()
                    ->action(function () {
                        $problems = app(ManageGitHub::class)->unlink($this->site());
                        $problems === []
                            ? Toast::success(__('GitHub odpojen'))
                            : Toast::warning(__('GitHub odpojen, v repozitáři ale něco zůstalo'), implode("\n", $problems));
                        $this->refreshHistory();
                    }),
            ])
            ->action(function (array $data) {
                $connection = GithubConnection::query()->findOrFail($data['connection']);
                try {
                    $out = app(ManageGitHub::class)->link($this->site(), $connection, $data);
                    Toast::success(__('Napojeno na :repo', ['repo' => $data['repo']]), $out['ci_secret']
                        ? __('Deploy klíč, webhook a secret WSPANEL_TOKEN jsou v repozitáři.')
                        : __('Deploy klíč je v repozitáři; deploy spustí :what.', ['what' => __(self::TRIGGERS[$out['trigger']] ?? $out['trigger'])]));
                    $this->refreshHistory();
                } catch (GitHubException|AgentException $e) {
                    Toast::error(__('Napojení na GitHub selhalo'), $e->getMessage());
                }
            });
    }

    /**
     * No GitHub account yet: the same button connects one (the panel's app,
     * or the Integrations page when there is none) and comes back here.
     */
    private function githubConnectAction(): Action
    {
        $here = '/'.ltrim((string) parse_url(SiteResource::url('deploy', $this->site()), PHP_URL_PATH), '/');

        return Action::make('githubConnect')
            ->label('GitHub')
            ->icon('outline:link')
            ->color('gray')
            ->visible(fn () => ! GithubConnection::query()->exists())
            ->tooltip(__('Propojit s GitHubem'))
            ->url(GitHubApp::configured() ? route('github.connect', ['return' => $here]) : IntegrationsPage::url())
            ->navigate(false);
    }

    /**
     * Options read from GitHub for the chosen account; an error shows as the
     * only option instead of breaking the modal.
     *
     * @param  callable(ManageGitHub, GithubConnection): array<string, string>  $read
     * @return array<string, string>
     */
    private function githubOptions(callable $read, mixed $connectionId): array
    {
        $connection = $connectionId ? GithubConnection::query()->find($connectionId) : null;
        if ($connection === null) {
            return [];
        }
        try {
            return $read(app(ManageGitHub::class), $connection);
        } catch (GitHubException $e) {
            Toast::error(__('GitHub'), $e->getMessage());

            return [];
        }
    }

    /** A panel on a local or private address cannot receive GitHub's webhooks. */
    public static function panelIsPublic(): bool
    {
        return PublicAddress::url((string) config('app.url'));
    }

    private function settingsAction(): Action
    {
        $site = $this->site();

        return Action::make('settings')
            ->label(__('Nastavení'))
            ->icon('outline:cog-6-tooth')
            ->color('gray')
            ->modalHeading(__('Nastavení deploye'))
            ->modalWidth('2xl')
            ->fillFormUsing(fn () => ['commands' => implode("\n", $this->config['commands'] ?? []), 'env_from_deploy' => (bool) ($this->config['env_from_deploy'] ?? false), 'maintenance' => (bool) ($this->config['maintenance'] ?? false)]
                + ($this->config ?? ['repo' => '', 'branch' => 'main', 'migrate' => true, 'assets' => true, 'auto_rollback' => true, 'keep' => 5])
                + ['opcache_production' => $this->opcacheProduction])
            ->form([
                TextInput::make('repo')
                    ->label(__('Repozitář'))
                    ->placeholder('git@github.com:firma/aplikace.git')
                    ->helperText(__('SSH adresa (s deploy klíčem) nebo veřejné https://. Bez hesla či tokenu v URL. Prázdné = web se nasazuje jen z CI (GitHub Actions…).'))
                    ->rules(['nullable', 'regex:~^(https://[A-Za-z0-9.-]+(:\d{1,5})?/[A-Za-z0-9._\~/-]+|git@[A-Za-z0-9.-]+:[A-Za-z0-9._\~/-]+|ssh://git@[A-Za-z0-9.-]+(:\d{1,5})?/[A-Za-z0-9._\~/-]+)$~']),
                TextInput::make('branch')->label(__('Větev'))->required()->rules(['regex:~^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$~']),
                Toggle::make('migrate')
                    ->label(match (true) {
                        $site->framework === Framework::Symfony => __('Spouštět Doctrine migrace'),
                        $site->framework === Framework::Django => __('Spouštět migrace (manage.py migrate)'),
                        $site->framework->isPython() => __('Spouštět migrace Alembicu (je-li alembic.ini)'),
                        default => __('Spouštět migrace (artisan migrate --force)'),
                    })
                    ->visible(in_array($site->framework, [Framework::Laravel, Framework::Symfony], true) || $site->framework->isPython()),
                Toggle::make('assets')
                    ->label($site->framework === Framework::Django ? __('Statické soubory (npm build, je-li package.json, a collectstatic)') : __('Buildit assety (npm ci + npm run build)'))
                    ->visible(! $site->framework->isNode()),
                Toggle::make('maintenance')
                    ->label(__('Stránka údržby během migrací'))
                    ->helperText(__('Pro migrace, se kterými starý kód nepoběží: návštěvníci uvidí „Probíhá údržba“ od migrací do chvíle, kdy nový release odpovídá. Odkaz pro vstup správce je v logu deploye.'))
                    ->visibleWhen('migrate', true)
                    ->visible(in_array($site->framework, [Framework::Laravel, Framework::Symfony], true) || $site->framework->isPython()),
                Toggle::make('auto_rollback')->label(__('Když nový release neodpovídá, vrátit předchozí')),
                Select::make('keep')->rules([Options::rule()])->label(__('Držet releasů'))->options(array_combine(range(2, 10), range(2, 10)))->required(),
                Textarea::make('commands')
                    ->label(match ($site->framework) {
                        Framework::Symfony => __('Vlastní příkazy po deployi (bin/console)'),
                        Framework::Django => __('Vlastní příkazy po deployi (manage.py)'),
                        default => __('Vlastní příkazy po deployi (artisan)'),
                    })
                    ->rows(4)
                    ->placeholder("install:permissions\nlivewire:publish --assets")
                    ->helperText(__('Jeden na řádek, běží po migracích a optimalizaci, před přepnutím na nový release. Jen příkazy konzole s argumenty, žádný shell.'))
                    ->rules(['nullable', 'string', 'max:2000'])
                    ->visible(in_array($site->framework, [Framework::Laravel, Framework::Symfony, Framework::Django], true)),
                Toggle::make('env_from_deploy')
                    ->label(__('Konfigurace z deploye má přednost (.env z CI / GitHub Secrets)'))
                    ->helperText(__('Vypnuto: klíče, které nastavil panel (databáze, pošta, Valkey, Reverb), zůstanou panelové a zbytek doplní .env z releasu. Zapnuto: platí .env z releasu celý; panel hlídá jen port, na kterém poslouchá Reverb.')),
                Toggle::make('opcache_production')
                    ->label(__('Produkční OPcache (nekontrolovat změny souborů)'))
                    ->helperText(__('Rychlejší. Ruční úpravy přes SFTP se pak projeví až po restartu webu nebo dalším deployi.'))
                    ->visible($site->mode === SiteMode::Classic),
            ])
            ->action(function (array $data) {
                try {
                    app(ManageDeploys::class)->configure($this->site(), $data + ['migrate' => false, 'assets' => false]);
                    if ($this->site()->mode === SiteMode::Classic && (bool) ($data['opcache_production'] ?? false) !== $this->opcacheProduction) {
                        app(ManageDeploys::class)->opcache($this->site(), (bool) $data['opcache_production']);
                    }
                    $this->refreshHistory();
                    Toast::success(__('Nastavení deploye uloženo'));
                } catch (AgentException $e) {
                    Toast::error(__('Nastavení se nepodařilo uložit'), $e->getMessage());
                }
            });
    }

    private function automationAction(): Action
    {
        return Action::make('automation')
            ->label(__('Automatický deploy'))
            ->icon('outline:bolt')
            ->color(fn () => $this->site()->webhook_enabled ? 'success' : 'gray')
            ->modalHeading(__('Automatický deploy'))
            ->modalWidth('3xl')
            ->fillFormUsing(fn () => [
                'enabled' => $this->site()->webhook_enabled,
                'trigger' => $this->site()->deploy_trigger ?? 'branch',
            ])
            ->form(fn () => [
                Toggle::make('enabled')->label(__('Zapnuto')),
                Radio::make('trigger')->rules([Options::rule()])
                    ->label(__('Co spustí deploy'))
                    ->options(Lang::values(self::TRIGGERS))
                    ->descriptions([
                        'branch' => __('Git hosting pošle webhook při pushi do větve ').($this->config['branch'] ?? 'main').__('. Panel musí být z internetu dostupný na adrese webhooku.'),
                        'tags' => __('GitHub/Gitea: událost „Releases“ (published) nebo push tagu. GitLab: „Tag push events“ / „Releases events“. Nasadí se přesně ten tag.'),
                        'poll_branch' => __('Nic nemusí server zvenku volat: hodí se, když panel není veřejný nebo je server za NATem.'),
                        'poll_tags' => __('Nasadí nejnovější tag podle čísla verze (v1.10.0 > v1.9.3), jakmile se v repozitáři objeví.'),
                    ])
                    ->required()
                    ->live(),
                TextInput::make('url')->label(__('Payload URL (webhook)'))->readOnly()
                    ->default(fn () => ManageWebhook::url($this->site()) ?? __('vytvoří se po zapnutí'))
                    ->helperText(fn () => ManageWebhook::panelReachable() ? null : __('Pro git server ve stejné síti jako panel (Gitea, GitLab).'))
                    ->hiddenWhen('trigger', ['poll_branch', 'poll_tags']),
                TextInput::make('public_url')->label(__('Payload URL z internetu (GitHub.com)'))->readOnly()
                    ->default(fn () => ManageWebhook::publicUrl($this->site())
                        ?? (ManageWebhook::reachable($this->site()) ? __('vytvoří se po zapnutí') : __('není: panel ani web nejsou z internetu dostupné, zvolte kontrolu repozitáře')))
                    ->helperText(__('Panel z internetu dostupný není, proto přes veřejnou doménu webu; její edge předá panelu jen tento podepsaný požadavek.'))
                    ->visible(fn () => ! ManageWebhook::panelReachable())
                    ->hiddenWhen('trigger', ['poll_branch', 'poll_tags']),
                TextInput::make('secret')->label(__('Secret'))->readOnly()
                    ->default(fn () => $this->site()->webhook_secret ?? __('vytvoří se po zapnutí'))
                    ->hiddenWhen('trigger', ['poll_branch', 'poll_tags']),
                Placeholder::make('poll_status')
                    ->label(__('Poslední kontrola repozitáře'))
                    ->content(function () {
                        $site = $this->site();
                        if (! in_array($site->deploy_trigger, ['poll_branch', 'poll_tags'], true) || $site->deploy_polled_at === null) {
                            return __('Zatím neproběhla. Panel kontroluje repozitář jednou za minutu.');
                        }

                        return $site->deploy_poll_error !== null
                            ? $site->deploy_polled_at->diffForHumans().__(': chyba – ').$site->deploy_poll_error
                            : $site->deploy_polled_at->diffForHumans().': '.($site->deploy_trigger === 'poll_tags' ? __('nejnovější tag ') : __('commit ')).Str::limit((string) $site->deploy_polled_ref, 12, '');
                    })
                    ->visibleWhen('trigger', ['poll_branch', 'poll_tags']),
                Placeholder::make('help')->hiddenLabel()
                    ->content(__('GitHub / Gitea / Forgejo: Settings → Webhooks, content type application/json, secret výše. GitLab: Settings → Webhooks, Secret token = secret. Bitbucket: Repository settings → Webhooks.'))
                    ->hiddenWhen('trigger', ['poll_branch', 'poll_tags']),
            ])
            ->modalFooterActions([
                ModalFooterAction::make('rotateWebhook')
                    ->label(__('Nové URL a secret'))
                    ->icon('outline:arrow-path')
                    ->color('warning')
                    ->position('before')
                    ->confirm(__('Staré URL i secret přestanou okamžitě platit; v repozitáři je bude potřeba zadat znovu. Pokračovat?'))
                    ->action(function ($set) {
                        $site = app(ManageWebhook::class)->rotate($this->site());
                        $set('url', ManageWebhook::url($site) ?? '');
                        $set('public_url', ManageWebhook::publicUrl($site) ?? '');
                        $set('secret', (string) $site->webhook_secret);
                        Toast::success(__('Webhook má nové URL a secret'), __('Zadejte je znovu v nastavení repozitáře.'));
                    }),
            ])
            ->action(function (array $data) {
                $site = $this->site();
                $trigger = $data['trigger'] ?? 'branch';
                $site->update($trigger === $site->deploy_trigger
                    ? ['deploy_trigger' => $trigger]
                    : ['deploy_trigger' => $trigger, 'deploy_polled_ref' => null, 'deploy_polled_at' => null, 'deploy_poll_error' => null]);
                $data['enabled'] ? app(ManageWebhook::class)->enable($site) : app(ManageWebhook::class)->disable($site);
                Toast::success($data['enabled'] ? __('Automatický deploy zapnut') : __('Automatický deploy vypnut'), $data['enabled'] ? __(self::TRIGGERS[$data['trigger']]) : null);
            });
    }

    private function ciAction(): Action
    {
        return Action::make('ci')
            ->label('GitHub Actions / CI')
            ->icon('outline:command-line')
            ->modalHeading(__('Deploy z CI (GitHub Actions, GitLab CI…)'))
            ->modalWidth('5xl')
            ->form(fn () => [
                Placeholder::make('ci')->hiddenLabel()->html(view('livewire.sites.deploy-ci', [
                    'enabled' => $this->site()->ci_token_hash !== null,
                    'base' => $this->site()->ci_id ? route('api.deploy.trigger', ['hook' => $this->site()->ci_id]) : null,
                    'framework' => $this->site()->framework,
                ])->render()),
                Toggle::make('new_token')->label($this->site()->ci_token_hash ? __('Vytvořit nový token (starý přestane platit)') : __('Vytvořit CI token')),
                Toggle::make('revoke')->label(__('Zrušit token'))->visible($this->site()->ci_token_hash !== null),
            ])
            ->modalSubmitActionLabel(__('Uložit'))
            ->action(function (array $data) {
                if ($data['revoke'] ?? false) {
                    app(ManageCiToken::class)->revoke($this->site());
                    Toast::success(__('CI token zrušen'));

                    return;
                }
                if ($data['new_token'] ?? false) {
                    $token = app(ManageCiToken::class)->generate($this->site());
                    Toast::secret(__('CI token (zobrazí se jen teď)'), __('Uložte ho v GitHubu jako secret WSPANEL_TOKEN:
:token', ['token' => $token]));
                }
            });
    }

    private function deployKeyAction(): Action
    {
        return Action::make('deployKey')
            ->label(__('Deploy klíč'))
            ->icon('outline:key')
            ->modalHeading(__('Deploy klíč'))
            ->modalDescription(__('Přidejte veřejný klíč k repozitáři jako deploy key jen pro čtení (GitHub: Settings → Deploy keys).'))
            ->fillFormUsing(function () {
                try {
                    return ['key' => app(ManageDeploys::class)->publicKey($this->site())];
                } catch (AgentException $e) {
                    return ['key' => 'Chyba: '.$e->getMessage()];
                }
            })
            ->form([Textarea::make('key')->label(__('Veřejný klíč'))->rows(3)->readOnly()])
            ->modalSubmitActionLabel(__('Zavřít'))
            ->action(fn () => null);
    }

    private function envAction(): Action
    {
        return Action::make('env')
            ->label('.env')
            ->icon('outline:document-text')
            ->modalHeading(__('Sdílený .env'))
            ->modalWidth('4xl')
            ->modalDescription(__('Soubor ve shared/, společný pro všechny releasy. Hodnoty DB_*, MAIL_* a MAILER_DSN spravuje panel. Proces aplikace a workery načtou změny po restartu.'))
            ->fillFormUsing(function () {
                try {
                    return ['content' => app(ManageDeploys::class)->envGet($this->site())];
                } catch (AgentException) {
                    return ['content' => ''];
                }
            })
            ->form([CodeIde::make('content')->hiddenLabel()->language('dotenv')->minHeight(480)->rules(['nullable', 'string', 'max:65536'])])
            ->action(function (array $data) {
                try {
                    app(ManageDeploys::class)->envSet($this->site(), (string) $data['content']);
                    Toast::success(__('.env uložen'));
                } catch (AgentException $e) {
                    Toast::error(__('Uložení selhalo'), $e->getMessage());
                }
            });
    }

    private function rollbackAction(): Action
    {
        return Action::make('rollback')
            ->label(__('Rollback'))
            ->icon('outline:arrow-uturn-left')
            ->color('warning')
            ->disabled(fn () => $this->running || array_search($this->current, $this->releases, true) < 1)
            ->modalHeading(__('Vrátit na předchozí release'))
            ->modalDescription(__('Web se okamžitě přepne zpět, bez nového buildu. Databázové migrace se nevracejí.'))
            ->form(fn () => [
                Select::make('release')->rules([Options::rule()])
                    ->label(__('Release'))
                    ->options(collect($this->releases)->reject(fn ($r) => $r === $this->current)->reverse()->mapWithKeys(fn ($r) => [$r => $r])->all())
                    ->default($this->releases[array_search($this->current, $this->releases, true) - 1] ?? null)
                    ->required(),
            ])
            ->action(function (array $data) {
                try {
                    $r = app(ManageDeploys::class)->rollback($this->site(), $data['release']);
                    $this->refreshHistory();
                    $r['healthy']
                        ? Toast::success(__('Web běží na releasu :current', ['current' => $r['current']]), __('Odpovídá.'))
                        : Toast::warning(__('Web běží na releasu :current', ['current' => $r['current']]), __('Pozor, neodpovídá: ').$r['health_error']);
                } catch (AgentException $e) {
                    Toast::error(__('Rollback selhal'), $e->getMessage());
                }
            });
    }

    public function render(): View
    {
        return view('livewire.sites.deploy', [
            'title' => $this->getTitle(),
            'breadcrumbs' => $this->breadcrumbs(),
            'headerActions' => $this->renderedHeaderActions(),
            'subNavigation' => $this->subNavigation($this->nativeRecord()),
            'initialLog' => $this->initialLog,
        ]);
    }
}
