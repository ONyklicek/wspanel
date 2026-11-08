<?php

namespace App\Livewire\Pages;

use App\Actions\Server\ManagePhp;
use App\Enums\SiteMode;
use App\Livewire\Concerns\TranslatesPageText;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Forms\Options;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Select;
use NyonCode\WirePanels\Pages\Page;

/**
 * PHP on the server: the PHP-FPM versions (classic sites) with their
 * extensions, and the PHP built into FrankenPHP (worker sites).
 */
class PhpPage extends Page
{
    use TranslatesPageText;

    protected static string $view = 'livewire.pages.php';

    /** Server-wide settings: administrators only. */
    protected static ?string $permission = 'admin';

    protected ?string $title = 'PHP';

    protected static ?string $slug = 'php';

    protected static ?string $navigationLabel = 'PHP';

    protected static ?string $navigationIcon = 'outline:code-bracket';

    protected static ?string $navigationGroup = 'server';

    protected static int $navigationSort = 25;

    /** @var array<string, mixed> */
    public array $status = [];

    public ?string $error = null;

    public function mount(): void
    {
        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        try {
            $this->status = app(ManagePhp::class)->status();
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** @return array<string, list<string>> domains per PHP version (classic sites) */
    public function sitesByVersion(): array
    {
        return Site::query()->where('mode', SiteMode::Classic)->orderBy('domain')->get(['domain', 'php_version'])
            ->groupBy('php_version')->map(fn ($s) => $s->pluck('domain')->all())->all();
    }

    /** @return list<string> */
    public function workerSites(): array
    {
        return Site::query()->where('mode', SiteMode::Worker)->orderBy('domain')->pluck('domain')->all();
    }

    protected function headerActions(): array
    {
        $installable = $this->status['installable'] ?? [];

        return [
            Action::make('installVersion')
                ->label(__('Doinstalovat verzi PHP'))
                ->icon('outline:plus')
                ->visible($installable !== [])
                ->modalDescription(__('Nainstaluje PHP-FPM a obvyklá rozšíření (intl, mbstring, xml, curl, zip, gd, redis…) včetně ovladačů databází. Weby pak verzi zvolí v Nastavení → Běh a PHP → Verze.'))
                ->form([Select::make('version')->rules([Options::rule()])->label(__('Verze'))->required()
                    ->options(collect($installable)->mapWithKeys(fn ($v) => [$v => "PHP {$v}"])->all())])
                ->action(function (array $data) {
                    try {
                        app(ManagePhp::class)->install($data['version'])
                            ? Toast::success(__('PHP :version nainstalováno', ['version' => $data['version']]))
                            : Toast::success(__('Instalace PHP :version běží na pozadí', ['version' => $data['version']]), __('Obnovte stránku za pár minut.'));
                    } catch (AgentException $e) {
                        Toast::error(__('Instalace selhala'), $e->getMessage());
                    }
                    $this->refreshStatus();
                }),
        ];
    }

    protected function actions(): array
    {
        $actions = [];
        foreach ($this->status['versions'] ?? [] as $v) {
            $actions[] = $this->addExtensionAction($v['version']);
            $actions[] = $this->removeExtensionAction($v['version']);
        }

        return $actions;
    }

    /** @return array<string, mixed> */
    private function version(string $version): array
    {
        return collect($this->status['versions'] ?? [])->firstWhere('version', $version) ?? [];
    }

    public function addExtensionAction(string $version): Action
    {
        $v = $this->version($version);
        $missing = array_values(array_diff($v['available'] ?? [], $v['extensions'] ?? []));

        return Action::make('addExtension_'.str_replace('.', '_', $version))
            ->label(__('Přidat rozšíření'))
            ->icon('outline:plus')
            ->color('gray')
            ->size('sm')
            ->disabled($missing === [])
            ->modalHeading(__('PHP :version: přidat rozšíření', ['version' => $version]))
            ->modalDescription(__('Nainstaluje balíček php').$version.__('-… z repozitáře a plynule znovu načte PHP-FPM.'))
            ->form([Select::make('extension')->label(__('Rozšíření'))->searchable()->required()->rules([Options::rule()])
                ->options(array_combine($missing, $missing))])
            ->action(fn (array $data) => $this->runExtension($version, $data['extension'], 'install'));
    }

    public function removeExtensionAction(string $version): Action
    {
        $installed = $this->version($version)['extensions'] ?? [];

        return Action::make('removeExtension_'.str_replace('.', '_', $version))
            ->label(__('Odebrat rozšíření'))
            ->icon('outline:minus')
            ->color('gray')
            ->size('sm')
            ->disabled($installed === [])
            ->modalHeading(__('PHP :version: odebrat rozšíření', ['version' => $version]))
            ->modalDescription(__('Weby na této verzi, které rozšíření používají, přestanou fungovat.'))
            ->form([Select::make('extension')->label(__('Rozšíření'))->searchable()->required()->rules([Options::rule()])
                ->options(array_combine($installed, $installed))])
            ->modalSubmitActionLabel(__('Odebrat'))
            ->action(fn (array $data) => $this->runExtension($version, $data['extension'], 'remove'));
    }

    private function runExtension(string $version, string $extension, string $action): void
    {
        try {
            $done = app(ManagePhp::class)->extension($version, $extension, $action);
            $verb = $action === 'install' ? __('nainstalováno') : __('odebráno');
            $done
                ? Toast::success("PHP {$version}: {$extension} {$verb}")
                : Toast::success(__('PHP :version: :extension se zpracovává na pozadí', ['version' => $version, 'extension' => $extension]), __('Obnovte stránku za chvíli.'));
        } catch (AgentException $e) {
            Toast::error(__('Změna rozšíření selhala'), $e->getMessage());
        }
        $this->refreshStatus();
    }
}
