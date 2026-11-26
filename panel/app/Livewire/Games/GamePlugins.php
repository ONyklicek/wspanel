<?php

namespace App\Livewire\Games;

use App\Actions\Games\ManageGameServers;
use App\Livewire\Games\Concerns\IsGameTab;
use App\Models\AgentTask;
use App\Services\Agent\AgentException;
use App\Support\AgentTasks;
use App\Support\Toast;
use App\Wire\Resources\GameServerResource;
use Illuminate\Contracts\View\View;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

/** A Paper server's plugins (Modrinth, Hangar) or a Factorio server's mods (the mod portal). */
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

    public string $packQuery = '';

    /** @var list<array<string, mixed>>|null */
    public ?array $packResults = null;

    /** @var array{project: string, name: string, version: string, files: int}|null */
    public ?array $pack = null;

    /** Factorio: whether server-settings.json has the factorio.com account. */
    public bool $account = false;

    public function mount(mixed $record = null): void
    {
        parent::mount($record);
        $this->load();
    }

    protected function tabLabel(): string
    {
        return $this->mods() ? __('Módy') : __('Pluginy');
    }

    public function factorio(): bool
    {
        return $this->server()->game->is('factorio');
    }

    /** What the variant loads, as its package says (plugins, mods, or nothing). */
    private function addons(): ?array
    {
        return $this->server()->game->addons($this->server()->flavor);
    }

    /** Plugins (Paper, Folia) or mods (Fabric, Forge, NeoForge) from the Minecraft catalogues. */
    public function paper(): bool
    {
        return $this->server()->game->is('minecraft') && $this->addons() !== null;
    }

    /** Paper's plugins come from Modrinth and Hangar; the others' from Modrinth. */
    public function hangar(): bool
    {
        return (bool) ($this->addons()['hangar'] ?? false);
    }

    /** Modpacks (Modrinth's .mrpack) go onto the mod loaders' servers. */
    public function modpacks(): bool
    {
        return $this->server()->game->is('minecraft') && in_array($this->server()->flavor, ['neoforge', 'forge', 'fabric'], true);
    }

    public function findModpacks(): void
    {
        $this->validate(['packQuery' => ['nullable', 'string', 'max:100', 'not_regex:/[\r\n]/']]);
        try {
            ['results' => $this->packResults, 'installed' => $this->pack] = app(ManageGameServers::class)->searchModpacks($this->server(), trim($this->packQuery));
        } catch (AgentException $e) {
            Toast::error(__('Hledání selhalo'), $e->getMessage());
        }
    }

    public function installModpack(string $project): void
    {
        $hit = collect($this->packResults ?? [])->firstWhere('project', $project);
        if (! $this->modpacks() || $hit === null || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $project)) {
            return;
        }
        try {
            app(ManageGameServers::class)->installModpack($this->server(), $project, (string) $hit['name']);
        } catch (AgentException $e) {
            Toast::error(__('Modpack se nepodařilo nainstalovat'), $e->getMessage());
        }
    }

    /** "Módy" for the mod loaders and Factorio, "Pluginy" for Paper and Folia. */
    public function mods(): bool
    {
        return $this->factorio() || ($this->addons()['kind'] ?? null) === 'mods';
    }

    public function load(bool $check = false): void
    {
        if (! $this->paper() && ! $this->factorio()) {
            return;
        }
        try {
            if ($this->factorio()) {
                $out = app(ManageGameServers::class)->mods($this->server(), $check);
                [$this->installed, $this->account] = [$out['mods'] ?? [], (bool) ($out['account'] ?? false)];
            } else {
                $this->installed = app(ManageGameServers::class)->plugins($this->server(), $check);
            }
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
        if (! $this->hangar()) {
            $this->source = 'modrinth';
        }
        $this->validate([
            'source' => ['required', 'in:modrinth,hangar,factorio'],
            'query' => ['nullable', 'string', 'max:100', 'not_regex:/[\r\n]/'],
        ]);
        try {
            $this->results = $this->factorio()
                ? array_map(fn ($m) => ['source' => 'factorio', 'project' => $m['name'], 'name' => $m['title'] ?: $m['name'], 'description' => $m['summary'] ?? '',
                    'author' => $m['owner'] ?? '', 'downloads' => $m['downloads_count'] ?? 0, 'url' => 'https://mods.factorio.com/mod/'.rawurlencode($m['name'])],
                    app(ManageGameServers::class)->searchMods($this->server(), trim($this->query)))
                : app(ManageGameServers::class)->searchPlugins($this->server(), $this->source, trim($this->query));
        } catch (AgentException $e) {
            Toast::error(__('Hledání selhalo'), $e->getMessage());
        }
    }

    /** A plugin or mod install ended: the list as it is now, and the restart hint. */
    protected function agentTaskFinished(AgentTask $task): void
    {
        if ($task->status === AgentTask::DONE && in_array($task->op, ['game.plugin_install', 'game.mod_install'], true)) {
            $this->changed = true;
        }
        if ($task->op === 'game.modpack_install' && $this->packResults !== null) {
            $this->findModpacks();
        }
        $this->load($this->checked);
    }

    public function install(string $source, string $project): void
    {
        $factorio = $this->factorio();
        if (! in_array($source, $factorio ? ['factorio'] : ($this->hangar() ? ['modrinth', 'hangar'] : ['modrinth']), true) || ! preg_match($factorio ? '/^[A-Za-z0-9][A-Za-z0-9 _.-]{0,99}$/' : '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $project)) {
            return;
        }
        try {
            $factorio
                ? app(ManageGameServers::class)->installMod($this->server(), $project)
                : app(ManageGameServers::class)->installPlugin($this->server(), $source, $project);
        } catch (AgentException $e) {
            Toast::error(__('Plugin se nepodařilo nainstalovat'), $e->getMessage());
        }
        $this->load($this->checked);
    }

    public function remove(string $file): void
    {
        try {
            $this->factorio()
                ? app(ManageGameServers::class)->removeMod($this->server(), $file)
                : app(ManageGameServers::class)->removePlugin($this->server(), $file);
            Toast::success(__(':file odebrán', ['file' => $file]), __('Jeho nastavení (složka v plugins) zůstalo. Projeví se po restartu serveru.'));
            $this->changed = true;
        } catch (AgentException $e) {
            Toast::error(__('Odebrání selhalo'), $e->getMessage());
        }
        $this->load($this->checked);
    }

    public function restart(): void
    {
        $server = $this->server();
        AgentTasks::queue('game.restart', GameServerStatus::controlText($server->name, 'restart'), ManageGameServers::class, 'control', [$server, 'restart'], $server);
        $this->changed = false;
    }

    public function render(): View
    {
        return $this->tabView('livewire.games.plugins');
    }
}
