<?php

namespace App\Livewire\Sites;

use App\Actions\Sites\ManageCiToken;
use App\Actions\Sites\ManageDeploys;
use App\Actions\Sites\ManageWebhook;
use App\Enums\Framework;
use App\Enums\SiteMode;
use App\Models\Deployment;
use App\Models\Site;
use App\Models\WebhookDelivery;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Forms\Options;
use App\Wire\Resources\SiteResource;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use NyonCode\WireCore\Actions\Action;
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

    public string $log = '';

    public int $logOffset = 0;

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
            $this->refreshLog();
        }
    }

    public function site(): Site
    {
        /** @var Site */
        return $this->nativeRecord();
    }

    public function getTitle(): ?string
    {
        return 'Deploy · '.$this->site()->domain;
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
            Toast::error('Agent není dostupný', $e->getMessage());
        }
    }

    /** Polled while a deploy runs: appends new log output. */
    public function refreshLog(): void
    {
        if (! $this->logRelease) {
            return;
        }
        try {
            $chunk = app(ManageDeploys::class)->log($this->site(), $this->logRelease, $this->logOffset);
        } catch (AgentException) {
            return;
        }
        $this->log .= $chunk['content'];
        $this->logOffset = $chunk['offset'];
        $this->logStatus = $chunk['status'];
        if ($this->logStatus !== 'running' && $this->running) {
            $this->refreshHistory();
        }
    }

    public function showLog(string $release): void
    {
        abort_unless(preg_match('/^\d{8}T\d{6}Z$/', $release) === 1, 404);
        $this->logRelease = $release;
        $this->log = '';
        $this->logOffset = 0;
        $this->refreshLog();
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
            ['Stažení kódu', 'git clone větve '.($c['branch'] ?? 'main').' (nebo tagu či commitu) do nové složky releases/<čas>, případně rozbalení hotového balíku z CI. Běžící web se zatím nemění.'],
            ['Sdílené soubory', match (true) {
                $f === Framework::Laravel => 'Do releasu se nalinkují .env a storage/ ze shared/, takže data a nahrané soubory přežívají deploye.',
                $f === Framework::Symfony => 'Do releasu se nalinkují .env.local a var/log ze shared/.',
                default => 'Do releasu se nalinkuje .env ze shared/.',
            }],
        ];
        if (! $f->isNode()) {
            $steps[] = ['Composer', 'composer install --no-dev, pokud repozitář obsahuje composer.json (u balíku z CI se přeskočí).'];
        }
        if ($f->isNode() || ($c['assets'] ?? false)) {
            $steps[] = ['Build assetů', 'npm ci a npm run build (Vite, Nuxt, Next…), pokud je v repozitáři package.json.'];
        }
        if ($f === Framework::Laravel) {
            if ($c['migrate'] ?? false) {
                $steps[] = ['Migrace', 'php artisan migrate --force.'];
            }
            $steps[] = ['Optimalizace', 'storage:link a artisan optimize.'];
        }
        if ($f === Framework::Symfony) {
            if ($c['migrate'] ?? false) {
                $steps[] = ['Migrace', 'doctrine:migrations:migrate.'];
            }
            $steps[] = ['Cache', 'asset-map:compile a cache:warmup pro prod.'];
        }
        $steps[] = ['Přepnutí', 'current/ se atomicky přepne na nový release. '.($site->mode === SiteMode::Classic ? 'PHP-FPM se plynule znovu načte.' : 'Proces aplikace se restartuje.').' Workery se restartují s novým kódem.'];
        $steps[] = ['Kontrola', ($c['auto_rollback'] ?? false)
            ? 'Panel ověří, že web odpovídá. Když ne, vrátí předchozí release.'
            : 'Panel ověří, že web odpovídá (automatický rollback je vypnutý).'];
        $steps[] = ['Úklid', 'Zůstane posledních '.($c['keep'] ?? 5).' releasů pro okamžitý rollback.'];

        return $steps;
    }

    protected function headerActions(): array
    {
        return [
            Action::make('deploy')
                ->label('Deploy')
                ->icon('outline:rocket-launch')
                ->disabled(fn () => $this->config === null || $this->running)
                ->requiresConfirmation()
                ->modalHeading('Spustit deploy?')
                ->modalDescription(fn () => 'Nasadí větev '.($this->config['branch'] ?? '?').'. Build běží pod uživatelem webu; web se přepne až po úspěšném buildu.')
                ->modalSubmitActionLabel('Nasadit')
                ->action(function () {
                    try {
                        $release = app(ManageDeploys::class)->start($this->site());
                        $this->running = true;
                        if ($release) {
                            $this->showLog($release);
                        }
                    } catch (AgentException $e) {
                        Toast::error('Deploy nelze spustit', $e->getMessage());
                    }
                }),
            $this->settingsAction(),
            $this->automationAction(),
            ActionGroup::make([
                $this->ciAction(),
                $this->deployKeyAction(),
                $this->envAction(),
                $this->rollbackAction(),
            ])->label('Další')->icon('outline:ellipsis-vertical'),
        ];
    }

    private function settingsAction(): Action
    {
        $site = $this->site();

        return Action::make('settings')
            ->label('Nastavení')
            ->icon('outline:cog-6-tooth')
            ->color('gray')
            ->modalHeading('Nastavení deploye')
            ->modalWidth('2xl')
            ->fillFormUsing(fn () => ($this->config ?? ['repo' => '', 'branch' => 'main', 'migrate' => true, 'assets' => true, 'auto_rollback' => true, 'keep' => 5])
                + ['opcache_production' => $this->opcacheProduction])
            ->form([
                TextInput::make('repo')
                    ->label('Repozitář')
                    ->placeholder('git@github.com:firma/aplikace.git')
                    ->helperText('SSH adresa (s deploy klíčem) nebo veřejné https://. Bez hesla či tokenu v URL.')
                    ->required()
                    ->rules(['regex:~^(https://[A-Za-z0-9.-]+(:\d{1,5})?/[A-Za-z0-9._\~/-]+|git@[A-Za-z0-9.-]+:[A-Za-z0-9._\~/-]+|ssh://git@[A-Za-z0-9.-]+(:\d{1,5})?/[A-Za-z0-9._\~/-]+)$~']),
                TextInput::make('branch')->label('Větev')->required()->rules(['regex:~^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$~']),
                Toggle::make('migrate')
                    ->label($site->framework === Framework::Symfony ? 'Spouštět Doctrine migrace' : 'Spouštět migrace (artisan migrate --force)')
                    ->visible(in_array($site->framework, [Framework::Laravel, Framework::Symfony], true)),
                Toggle::make('assets')->label('Buildit assety (npm ci + npm run build)')->visible(! $site->framework->isNode()),
                Toggle::make('auto_rollback')->label('Když nový release neodpovídá, vrátit předchozí'),
                Select::make('keep')->rules([Options::rule()])->label('Držet releasů')->options(array_combine(range(2, 10), range(2, 10)))->required(),
                Toggle::make('opcache_production')
                    ->label('Produkční OPcache (nekontrolovat změny souborů)')
                    ->helperText('Rychlejší. Ruční úpravy přes SFTP se pak projeví až po restartu webu nebo dalším deployi.')
                    ->visible($site->mode === SiteMode::Classic),
            ])
            ->action(function (array $data) {
                try {
                    app(ManageDeploys::class)->configure($this->site(), $data + ['migrate' => false, 'assets' => false]);
                    if ($this->site()->mode === SiteMode::Classic && (bool) ($data['opcache_production'] ?? false) !== $this->opcacheProduction) {
                        app(ManageDeploys::class)->opcache($this->site(), (bool) $data['opcache_production']);
                    }
                    $this->refreshHistory();
                    Toast::success('Nastavení deploye uloženo');
                } catch (AgentException $e) {
                    Toast::error('Nastavení se nepodařilo uložit', $e->getMessage());
                }
            });
    }

    private function automationAction(): Action
    {
        return Action::make('automation')
            ->label('Automatický deploy')
            ->icon('outline:bolt')
            ->color(fn () => $this->site()->webhook_enabled ? 'success' : 'gray')
            ->modalHeading('Automatický deploy')
            ->modalWidth('3xl')
            ->fillFormUsing(fn () => [
                'enabled' => $this->site()->webhook_enabled,
                'trigger' => $this->site()->deploy_trigger ?? 'branch',
            ])
            ->form(fn () => [
                Toggle::make('enabled')->label('Zapnuto'),
                Radio::make('trigger')->rules([Options::rule()])
                    ->label('Co spustí deploy')
                    ->options(self::TRIGGERS)
                    ->descriptions([
                        'branch' => 'Git hosting pošle webhook při pushi do větve '.($this->config['branch'] ?? 'main').'. Panel musí být z internetu dostupný na adrese webhooku.',
                        'tags' => 'GitHub/Gitea: událost „Releases“ (published) nebo push tagu. GitLab: „Tag push events“ / „Releases events“. Nasadí se přesně ten tag.',
                        'poll_branch' => 'Nic nemusí server zvenku volat: hodí se, když panel není veřejný nebo je server za NATem.',
                        'poll_tags' => 'Nasadí nejnovější tag podle čísla verze (v1.10.0 > v1.9.3), jakmile se v repozitáři objeví.',
                    ])
                    ->required()
                    ->live(),
                TextInput::make('url')->label('Payload URL (webhook)')->readOnly()
                    ->default(fn () => ManageWebhook::url($this->site()) ?? 'vytvoří se po zapnutí')
                    ->hiddenWhen('trigger', ['poll_branch', 'poll_tags']),
                TextInput::make('secret')->label('Secret')->readOnly()
                    ->default(fn () => $this->site()->webhook_secret ?? 'vytvoří se po zapnutí')
                    ->hiddenWhen('trigger', ['poll_branch', 'poll_tags']),
                Placeholder::make('poll_status')
                    ->label('Poslední kontrola repozitáře')
                    ->content(function () {
                        $site = $this->site();
                        if (! in_array($site->deploy_trigger, ['poll_branch', 'poll_tags'], true) || $site->deploy_polled_at === null) {
                            return 'Zatím neproběhla. Panel kontroluje repozitář jednou za minutu.';
                        }

                        return $site->deploy_poll_error !== null
                            ? $site->deploy_polled_at->diffForHumans().': chyba – '.$site->deploy_poll_error
                            : $site->deploy_polled_at->diffForHumans().': '.($site->deploy_trigger === 'poll_tags' ? 'nejnovější tag ' : 'commit ').Str::limit((string) $site->deploy_polled_ref, 12, '');
                    })
                    ->visibleWhen('trigger', ['poll_branch', 'poll_tags']),
                Placeholder::make('help')->hiddenLabel()
                    ->content('GitHub / Gitea / Forgejo: Settings → Webhooks, content type application/json, secret výše. GitLab: Settings → Webhooks, Secret token = secret. Bitbucket: Repository settings → Webhooks.')
                    ->hiddenWhen('trigger', ['poll_branch', 'poll_tags']),
            ])
            ->modalFooterActions([
                ModalFooterAction::make('rotateWebhook')
                    ->label('Nové URL a secret')
                    ->icon('outline:arrow-path')
                    ->color('warning')
                    ->position('before')
                    ->confirm('Staré URL i secret přestanou okamžitě platit; v repozitáři je bude potřeba zadat znovu. Pokračovat?')
                    ->action(function ($set) {
                        $site = app(ManageWebhook::class)->rotate($this->site());
                        $set('url', ManageWebhook::url($site) ?? '');
                        $set('secret', (string) $site->webhook_secret);
                        Toast::success('Webhook má nové URL a secret', 'Zadejte je znovu v nastavení repozitáře.');
                    }),
            ])
            ->action(function (array $data) {
                $site = $this->site();
                $trigger = $data['trigger'] ?? 'branch';
                $site->update($trigger === $site->deploy_trigger
                    ? ['deploy_trigger' => $trigger]
                    : ['deploy_trigger' => $trigger, 'deploy_polled_ref' => null, 'deploy_polled_at' => null, 'deploy_poll_error' => null]);
                $data['enabled'] ? app(ManageWebhook::class)->enable($site) : app(ManageWebhook::class)->disable($site);
                Toast::success($data['enabled'] ? 'Automatický deploy zapnut' : 'Automatický deploy vypnut', $data['enabled'] ? self::TRIGGERS[$data['trigger']] : null);
            });
    }

    private function ciAction(): Action
    {
        return Action::make('ci')
            ->label('GitHub Actions / CI')
            ->icon('outline:command-line')
            ->modalHeading('Deploy z CI (GitHub Actions, GitLab CI…)')
            ->modalWidth('5xl')
            ->form(fn () => [
                Placeholder::make('ci')->hiddenLabel()->html(view('livewire.sites.deploy-ci', [
                    'enabled' => $this->site()->ci_token_hash !== null,
                    'base' => $this->site()->ci_id ? route('api.deploy.trigger', ['hook' => $this->site()->ci_id]) : null,
                    'framework' => $this->site()->framework,
                ])->render()),
                Toggle::make('new_token')->label($this->site()->ci_token_hash ? 'Vytvořit nový token (starý přestane platit)' : 'Vytvořit CI token'),
                Toggle::make('revoke')->label('Zrušit token')->visible($this->site()->ci_token_hash !== null),
            ])
            ->modalSubmitActionLabel('Uložit')
            ->action(function (array $data) {
                if ($data['revoke'] ?? false) {
                    app(ManageCiToken::class)->revoke($this->site());
                    Toast::success('CI token zrušen');

                    return;
                }
                if ($data['new_token'] ?? false) {
                    $token = app(ManageCiToken::class)->generate($this->site());
                    Toast::secret('CI token (zobrazí se jen teď)', "Uložte ho v GitHubu jako secret WSPANEL_TOKEN:\n{$token}");
                }
            });
    }

    private function deployKeyAction(): Action
    {
        return Action::make('deployKey')
            ->label('Deploy klíč')
            ->icon('outline:key')
            ->modalHeading('Deploy klíč')
            ->modalDescription('Přidejte veřejný klíč k repozitáři jako deploy key jen pro čtení (GitHub: Settings → Deploy keys).')
            ->fillFormUsing(function () {
                try {
                    return ['key' => app(ManageDeploys::class)->publicKey($this->site())];
                } catch (AgentException $e) {
                    return ['key' => 'Chyba: '.$e->getMessage()];
                }
            })
            ->form([Textarea::make('key')->label('Veřejný klíč')->rows(3)->readOnly()])
            ->modalSubmitActionLabel('Zavřít')
            ->action(fn () => null);
    }

    private function envAction(): Action
    {
        return Action::make('env')
            ->label('.env')
            ->icon('outline:document-text')
            ->modalHeading('Sdílený .env')
            ->modalWidth('4xl')
            ->modalDescription('Soubor ve shared/, společný pro všechny releasy. Hodnoty DB_*, MAIL_* a MAILER_DSN spravuje panel. Proces aplikace a workery načtou změny po restartu.')
            ->fillFormUsing(function () {
                try {
                    return ['content' => app(ManageDeploys::class)->envGet($this->site())];
                } catch (AgentException) {
                    return ['content' => ''];
                }
            })
            ->form([Textarea::make('content')->label('Obsah')->rows(20)->maxLength(65536)->rules(['max:65536'])->extraInputAttributes(['class' => 'font-mono text-xs'])])
            ->action(function (array $data) {
                try {
                    app(ManageDeploys::class)->envSet($this->site(), (string) $data['content']);
                    Toast::success('.env uložen');
                } catch (AgentException $e) {
                    Toast::error('Uložení selhalo', $e->getMessage());
                }
            });
    }

    private function rollbackAction(): Action
    {
        return Action::make('rollback')
            ->label('Rollback')
            ->icon('outline:arrow-uturn-left')
            ->color('warning')
            ->disabled(fn () => $this->running || array_search($this->current, $this->releases, true) < 1)
            ->modalHeading('Vrátit na předchozí release')
            ->modalDescription('Web se okamžitě přepne zpět, bez nového buildu. Databázové migrace se nevracejí.')
            ->form(fn () => [
                Select::make('release')->rules([Options::rule()])
                    ->label('Release')
                    ->options(collect($this->releases)->reject(fn ($r) => $r === $this->current)->reverse()->mapWithKeys(fn ($r) => [$r => $r])->all())
                    ->default($this->releases[array_search($this->current, $this->releases, true) - 1] ?? null)
                    ->required(),
            ])
            ->action(function (array $data) {
                try {
                    $r = app(ManageDeploys::class)->rollback($this->site(), $data['release']);
                    $this->refreshHistory();
                    $r['healthy']
                        ? Toast::success("Web běží na releasu {$r['current']}", 'Odpovídá.')
                        : Toast::warning("Web běží na releasu {$r['current']}", 'Pozor, neodpovídá: '.$r['health_error']);
                } catch (AgentException $e) {
                    Toast::error('Rollback selhal', $e->getMessage());
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
        ]);
    }
}
