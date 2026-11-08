<?php

namespace App\Livewire\Pages;

use App\Actions\System\ManageUpdates;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WirePanels\Pages\Page;

/** System updates, automatic security updates and fail2ban's bans. */
class UpdatesPage extends Page
{
    protected static string $view = 'livewire.pages.updates';

    protected static ?string $permission = 'admin';

    protected ?string $title = 'Aktualizace a zabezpečení';

    protected static ?string $slug = 'updates';

    protected static ?string $navigationLabel = 'Aktualizace';

    protected static ?string $navigationIcon = 'outline:arrow-path-rounded-square';

    protected static ?string $navigationGroup = 'server';

    protected static int $navigationSort = 35;

    /** @var array<string, mixed>|null */
    public ?array $updates = null;

    /** @var array<string, list<string>> */
    public array $bans = [];

    public ?string $error = null;

    public function mount(): void
    {
        $this->load();
    }

    public function load(bool $refresh = false): void
    {
        try {
            $this->updates = app(ManageUpdates::class)->status($refresh);
            $this->bans = app(ManageUpdates::class)->bans();
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function refresh(): void
    {
        $this->load(true);
        Toast::success('Seznam balíčků obnoven');
    }

    public function toggleAuto(): void
    {
        try {
            $on = ! ($this->updates['auto_security'] ?? false);
            app(ManageUpdates::class)->setAutoSecurity($on);
            Toast::success($on ? 'Bezpečnostní aktualizace se instalují samy' : 'Automatické aktualizace vypnuty');
        } catch (AgentException $e) {
            Toast::error('Změna selhala', $e->getMessage());
        }
        $this->load();
    }

    public function unban(string $ip): void
    {
        try {
            app(ManageUpdates::class)->unban($ip);
            Toast::success("{$ip} odblokována");
        } catch (AgentException $e) {
            Toast::error('Odblokování selhalo', $e->getMessage());
        }
        $this->load();
    }

    protected function headerActions(): array
    {
        $upgrade = fn (bool $security) => function () use ($security) {
            try {
                app(ManageUpdates::class)->upgrade($security)
                    ? Toast::success('Aktualizace nainstalovány')
                    : Toast::warning('Instalace ještě běží', 'Obnovte stránku za chvíli.');
            } catch (AgentException $e) {
                Toast::error('Aktualizace selhala', $e->getMessage());
            }
            $this->load();
        };

        return [
            Action::make('upgradeSecurity')->label('Nainstalovat bezpečnostní')->icon('outline:shield-check')
                ->visible(fn () => collect($this->updates['packages'] ?? [])->contains('security', true))
                ->requiresConfirmation()->modalDescription('Nainstaluje jen bezpečnostní aktualizace (unattended-upgrades). Služby se po aktualizaci samy restartují.')
                ->action($upgrade(true)),
            Action::make('upgradeAll')->label('Nainstalovat vše')->icon('outline:arrow-down-tray')->color('gray')
                ->visible(fn () => ! empty($this->updates['packages']))
                ->requiresConfirmation()->modalDescription('Nainstaluje všechny aktualizace. Konfigurační soubory, které panel upravil, zůstanou (nové výchozí se nepoužijí).')
                ->action($upgrade(false)),
        ];
    }
}
