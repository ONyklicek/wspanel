<?php

namespace App\Livewire\Games;

use App\Actions\Games\ManageGameServers;
use App\Livewire\Games\Concerns\IsGameTab;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Resources\GameServerResource;
use Illuminate\Contracts\View\View;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

/** A Paper server's plugins: from Modrinth and Hangar, checked by checksum. */
class GamePlugins extends ViewPage
{
    use IsGameTab;

    protected static ?string $resource = GameServerResource::class;

    /** @var list<array<string, mixed>> */
    public array $installed = [];

    /** @var list<array<string, mixed>>|null */
    public ?array $results = null;

    public string $source = 'modrinth';

    public string $query = '';

    public bool $checked = false;

    public bool $changed = false;

    public ?string $error = null;

    public function mount(mixed $record = null): void
    {
        parent::mount($record);
        $this->load();
    }

    protected function tabLabel(): string
    {
        return __('Pluginy');
    }

    public function paper(): bool
    {
        return $this->server()->game->value === 'minecraft' && $this->server()->flavor === 'paper';
    }

    public function load(bool $check = false): void
    {
        if (! $this->paper()) {
            return;
        }
        try {
            $this->installed = app(ManageGameServers::class)->plugins($this->server(), $check);
            $this->checked = $check;
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function checkUpdates(): void
    {
        $this->load(true);
        $n = collect($this->installed)->whereNotNull('update')->count();
        $n > 0 ? Toast::success(__('K dispozici jsou aktualizace'), (string) $n) : Toast::success(__('Pluginy jsou aktuální'));
    }

    public function findPlugins(): void
    {
        $this->validate([
            'source' => ['required', 'in:modrinth,hangar'],
            'query' => ['nullable', 'string', 'max:100', 'not_regex:/[\r\n]/'],
        ]);
        try {
            $this->results = app(ManageGameServers::class)->searchPlugins($this->server(), $this->source, trim($this->query));
        } catch (AgentException $e) {
            Toast::error(__('Hledání selhalo'), $e->getMessage());
        }
    }

    public function install(string $source, string $project): void
    {
        if (! in_array($source, ['modrinth', 'hangar'], true) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $project)) {
            return;
        }
        try {
            $out = app(ManageGameServers::class)->installPlugin($this->server(), $source, $project);
            if ($out === null) {
                Toast::warning(__('Instalace ještě běží'), __('Obnovte stránku za chvíli.'));
            } else {
                $names = collect($out['installed'])->map(fn ($p) => $p['name'].' '.$p['version'])->implode(', ');
                Toast::success(__('Nainstalováno: :names', ['names' => $names]),
                    $out['needs'] !== [] ? __('Plugin potřebuje i: :needs (nainstalujte je zvlášť).', ['needs' => implode(', ', $out['needs'])]) : __('Projeví se po restartu serveru.'));
                $this->changed = true;
            }
        } catch (AgentException $e) {
            Toast::error(__('Plugin se nepodařilo nainstalovat'), $e->getMessage());
        }
        $this->load($this->checked);
    }

    public function remove(string $file): void
    {
        try {
            app(ManageGameServers::class)->removePlugin($this->server(), $file);
            Toast::success(__(':file odebrán', ['file' => $file]), __('Jeho nastavení (složka v plugins) zůstalo. Projeví se po restartu serveru.'));
            $this->changed = true;
        } catch (AgentException $e) {
            Toast::error(__('Odebrání selhalo'), $e->getMessage());
        }
        $this->load($this->checked);
    }

    public function restart(): void
    {
        try {
            app(ManageGameServers::class)->control($this->server(), 'restart');
            Toast::success(__('Server se restartuje'));
            $this->changed = false;
        } catch (AgentException $e) {
            Toast::error(__('Restart selhal'), $e->getMessage());
        }
    }

    public function render(): View
    {
        return $this->tabView('livewire.games.plugins');
    }
}
