<?php

namespace App\Livewire\Pages;

use App\Actions\System\ManageUpdates;
use App\Livewire\Concerns\TranslatesPageText;
use App\Services\Agent\AgentException;
use App\Support\PanelRelease;
use App\Support\Toast;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WirePanels\Pages\Page;

/** System updates and automatic security updates. */
class UpdatesPage extends Page
{
    use TranslatesPageText;

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

    public function mount(): void
    {
        $this->load();
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

    public function refresh(): void
    {
        $this->load(true);
        Toast::success(__('Seznam balíčků obnoven'));
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

    protected function headerActions(): array
    {
        $upgrade = fn (bool $security) => function () use ($security) {
            try {
                app(ManageUpdates::class)->upgrade($security)
                    ? Toast::success(__('Aktualizace nainstalovány'))
                    : Toast::warning(__('Instalace ještě běží'), __('Obnovte stránku za chvíli.'));
            } catch (AgentException $e) {
                Toast::error(__('Aktualizace selhala'), $e->getMessage());
            }
            $this->load();
        };

        return [
            Action::make('upgradeSecurity')->label(__('Nainstalovat bezpečnostní'))->icon('outline:shield-check')
                ->visible(fn () => collect($this->updates['packages'] ?? [])->contains('security', true))
                ->requiresConfirmation()->modalDescription(__('Nainstaluje jen bezpečnostní aktualizace (unattended-upgrades). Služby se po aktualizaci samy restartují.'))
                ->action($upgrade(true)),
            Action::make('upgradeAll')->label(__('Nainstalovat vše'))->icon('outline:arrow-down-tray')->color('gray')
                ->visible(fn () => ! empty($this->updates['packages']))
                ->requiresConfirmation()->modalDescription(__('Nainstaluje všechny aktualizace. Konfigurační soubory, které panel upravil, zůstanou (nové výchozí se nepoužijí).'))
                ->action($upgrade(false)),
        ];
    }
}
