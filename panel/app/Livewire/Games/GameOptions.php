<?php

namespace App\Livewire\Games;

use App\Actions\Games\ManageGameServers;
use App\Models\GameServer;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A native game's own settings (Counter-Strike 2's map, mode, server token…):
 * a form made from what its package declares. The agent checks every value
 * against the package's rules; secrets are written, never read back.
 */
class GameOptions extends Component
{
    #[Locked]
    public int $serverId;

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $specs = [];

    /** @var array<string, string|bool> */
    public array $values = [];

    /** @var array<string, string> new values of secrets (empty: keep) */
    public array $secrets = [];

    /** @var array<string, bool> */
    #[Locked]
    public array $secretsSet = [];

    public ?string $error = null;

    public function mount(int $server): void
    {
        Gate::authorize('games');
        $this->serverId = $server;
        $this->server();
        $this->load();
    }

    public function server(): GameServer
    {
        return GameServer::query()->findOrFail($this->serverId);
    }

    public function load(): void
    {
        try {
            $out = app(ManageGameServers::class)->options($this->server());
            $this->specs = $out['settings'];
            $bools = collect($this->specs)->where('type', 'bool')->pluck('key')->all();
            // A checkbox needs a real boolean ("0" would read as ticked).
            $this->values = collect($out['values'])->map(fn ($v, $k) => in_array($k, $bools, true) ? $v === '1' : (string) $v)->all();
            $this->secretsSet = array_map('boolval', $out['secrets']);
            $this->secrets = array_fill_keys(array_keys($this->secretsSet), '');
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function save(bool $restart = false): void
    {
        Gate::authorize('games');
        $keys = collect($this->specs)->pluck('type', 'key');
        $values = collect($this->values)->only($keys->reject(fn ($t) => $t === 'secret')->keys())
            ->map(fn ($v) => is_bool($v) ? ($v ? '1' : '0') : (string) $v)->all();
        $secrets = collect($this->secrets)->only($keys->filter(fn ($t) => $t === 'secret')->keys())
            ->map(fn ($v) => trim((string) $v))->filter()->all();
        try {
            app(ManageGameServers::class)->setOptions($this->server(), $values, $secrets, restart: $restart);
            Toast::success(__('Nastavení uloženo'), $restart ? __('Server se restartuje.') : __('Platí po příštím restartu serveru.'));
            $this->dispatch('game-refresh');
        } catch (AgentException $e) {
            Toast::error(__('Nastavení se nepodařilo uložit'), $e->getMessage());
        }
        $this->load();
    }

    public function clearSecret(string $key): void
    {
        Gate::authorize('games');
        if (! array_key_exists($key, $this->secretsSet)) {
            return;
        }
        try {
            app(ManageGameServers::class)->setOptions($this->server(), [], [], [$key]);
            Toast::success(__('Odebráno'));
        } catch (AgentException $e) {
            Toast::error(__('Nastavení se nepodařilo uložit'), $e->getMessage());
        }
        $this->load();
    }

    public function render(): View
    {
        return view('livewire.games.options');
    }
}
