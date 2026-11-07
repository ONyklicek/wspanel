<?php

namespace App\Filament\Resources\Sites\Pages;

use App\Actions\Sites\ManageDeploys;
use App\Enums\Framework;
use App\Filament\Resources\Sites\SiteResource;
use App\Models\Deployment;
use App\Models\Site;
use App\Services\Agent\AgentException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * @property-read Site $record
 */
class DeploySite extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SiteResource::class;

    protected string $view = 'filament.resources.sites.pages.deploy-site';

    /** @var array<string, mixed>|null deploy settings as the agent has them */
    public ?array $config = null;

    public string $current = '';

    /** @var list<string> */
    public array $releases = [];

    public bool $running = false;

    public ?string $logRelease = null;

    public string $log = '';

    public int $logOffset = 0;

    public string $logStatus = '';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->refreshHistory();
        $this->logRelease = $this->deployments()->first()?->release;
        if ($this->logRelease) {
            $this->refreshLog();
        }
    }

    public function getTitle(): string|Htmlable
    {
        return "Deploy · {$this->record->domain}";
    }

    /** @return Collection<int, Deployment> */
    public function deployments(): Collection
    {
        return Deployment::query()->where('site_id', $this->record->id)->latest('started_at')->limit(20)->get();
    }

    public function refreshHistory(): void
    {
        try {
            $h = app(ManageDeploys::class)->history($this->record);
            $this->config = $h['config'];
            $this->current = $h['current'];
            $this->releases = $h['releases'];
            $this->running = $h['running'];
        } catch (AgentException $e) {
            Notification::make()->title('Agent není dostupný')->body($e->getMessage())->danger()->send();
        }
    }

    /** Polled while a deploy runs: appends new log output. */
    public function refreshLog(): void
    {
        if (! $this->logRelease) {
            return;
        }
        try {
            $chunk = app(ManageDeploys::class)->log($this->record, $this->logRelease, $this->logOffset);
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
        $this->logRelease = $release;
        $this->log = '';
        $this->logOffset = 0;
        $this->refreshLog();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('deploy')
                ->label('Deploy')
                ->icon(Heroicon::OutlinedRocketLaunch)
                ->disabled(fn () => $this->config === null || $this->running)
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Nasadí větev '.($this->config['branch'] ?? '?').'. Build běží pod uživatelem webu; web se přepne až po úspěšném buildu.')
                ->action(function () {
                    try {
                        $release = app(ManageDeploys::class)->start($this->record);
                        $this->running = true;
                        if ($release) {
                            $this->showLog($release);
                        }
                    } catch (AgentException $e) {
                        Notification::make()->title('Deploy nelze spustit')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            Action::make('settings')
                ->label('Nastavení')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->fillForm(fn () => $this->config ?? ['branch' => 'main', 'migrate' => true, 'assets' => true, 'auto_rollback' => true, 'keep' => 5])
                ->schema([
                    TextInput::make('repo')
                        ->label('Repozitář')
                        ->placeholder('git@github.com:firma/aplikace.git')
                        ->helperText('SSH adresa (s deploy klíčem) nebo veřejné https://. Bez hesla či tokenu v URL.')
                        ->required()
                        ->regex('~^(https://[A-Za-z0-9.-]+(:\d{1,5})?/[A-Za-z0-9._\~/-]+|git@[A-Za-z0-9.-]+:[A-Za-z0-9._\~/-]+|ssh://git@[A-Za-z0-9.-]+(:\d{1,5})?/[A-Za-z0-9._\~/-]+)$~'),
                    TextInput::make('branch')->label('Větev')->required()->regex('~^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$~'),
                    Toggle::make('migrate')
                        ->label(fn () => $this->record->framework === Framework::Symfony ? 'Spouštět Doctrine migrace' : 'Spouštět migrace (artisan migrate --force)')
                        ->visible(fn () => in_array($this->record->framework, [Framework::Laravel, Framework::Symfony], true)),
                    Toggle::make('assets')->label('Buildit assety (npm install + npm run build)')->visible(fn () => ! $this->record->framework->isNode()),
                    Toggle::make('auto_rollback')->label('Když nový release neodpovídá, vrátit předchozí'),
                    Select::make('keep')->label('Držet releasů')->options(array_combine(range(2, 10), range(2, 10)))->required(),
                ])
                ->action(function (array $data) {
                    try {
                        app(ManageDeploys::class)->configure($this->record, $data + ['migrate' => false, 'assets' => false]);
                        $this->refreshHistory();
                        Notification::make()->title('Nastavení deploye uloženo')->success()->send();
                    } catch (AgentException $e) {
                        Notification::make()->title('Nastavení se nepodařilo uložit')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            Action::make('deployKey')
                ->label('Deploy klíč')
                ->icon(Heroicon::OutlinedKey)
                ->color('gray')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Zavřít')
                ->modalDescription('Přidejte veřejný klíč k repozitáři jako deploy key jen pro čtení (GitHub: Settings → Deploy keys).')
                ->fillForm(function () {
                    try {
                        return ['key' => app(ManageDeploys::class)->publicKey($this->record)];
                    } catch (AgentException $e) {
                        return ['key' => 'Chyba: '.$e->getMessage()];
                    }
                })
                ->schema([Textarea::make('key')->label('Veřejný klíč')->rows(3)->readOnly()]),
            Action::make('env')
                ->label('.env')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->modalWidth('4xl')
                ->modalDescription('Soubor ve shared/, společný pro všechny releasy. Aplikace v worker režimu a workery načtou změny po restartu nebo dalším deployi.')
                ->fillForm(function () {
                    try {
                        return ['content' => app(ManageDeploys::class)->envGet($this->record)];
                    } catch (AgentException $e) {
                        return ['content' => ''];
                    }
                })
                ->schema([Textarea::make('content')->label('Obsah')->rows(20)->extraInputAttributes(['class' => 'font-mono text-xs'])->maxLength(65536)])
                ->action(function (array $data) {
                    try {
                        app(ManageDeploys::class)->envSet($this->record, (string) $data['content']);
                        Notification::make()->title('.env uložen')->success()->send();
                    } catch (AgentException $e) {
                        Notification::make()->title('Uložení selhalo')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            Action::make('rollback')
                ->label('Rollback')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('warning')
                ->disabled(fn () => $this->running || array_search($this->current, $this->releases, true) < 1)
                ->schema(fn () => [
                    Select::make('release')
                        ->label('Vrátit na release')
                        ->options(collect($this->releases)->reject(fn ($r) => $r === $this->current)->reverse()->mapWithKeys(fn ($r) => [$r => $r])->all())
                        ->default(fn () => $this->releases[array_search($this->current, $this->releases, true) - 1] ?? null)
                        ->required(),
                ])
                ->action(function (array $data) {
                    try {
                        $r = app(ManageDeploys::class)->rollback($this->record, $data['release']);
                        $this->refreshHistory();
                        Notification::make()
                            ->title("Web běží na releasu {$r['current']}")
                            ->body($r['healthy'] ? 'Odpovídá.' : 'Pozor, neodpovídá: '.$r['health_error'])
                            ->color($r['healthy'] ? 'success' : 'warning')
                            ->send();
                    } catch (AgentException $e) {
                        Notification::make()->title('Rollback selhal')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
        ];
    }
}
