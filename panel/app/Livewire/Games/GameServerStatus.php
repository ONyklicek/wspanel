<?php

namespace App\Livewire\Games;

use App\Actions\Games\ManageGameServers;
use App\Models\GameServer;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A game server's state, live: running or not, since when, CPU and memory,
 * who plays, and the buttons to start and stop it. Polls itself (faster while
 * the server is getting somewhere), so the page around it is never redrawn
 * for a tick, and tells the page (game-state-changed) when the state moves.
 */
class GameServerStatus extends Component
{
    /** How long a start, stop or restart may take before the badge gives up waiting. */
    private const PENDING_SECONDS = 120;

    #[Locked]
    public int $serverId;

    /** @var array<string, mixed>|null */
    public ?array $live = null;

    public ?string $error = null;

    /** start | stop | restart while it has not happened yet. */
    #[Locked]
    public ?string $pending = null;

    #[Locked]
    public ?int $pendingSince = null;

    /** The previous CPU sample: two of them give the load. */
    #[Locked]
    public ?array $cpuSample = null;

    #[Locked]
    public ?float $cpuPercent = null;

    #[Locked]
    public ?string $state = null;

    public function mount(int $server): void
    {
        Gate::authorize('games');
        $this->serverId = $server;
        $this->server(); // another client's server: 404
        $this->refresh();
    }

    public function server(): GameServer
    {
        // The client scope narrows this to the signed-in client's servers.
        return GameServer::query()->findOrFail($this->serverId);
    }

    /** A background task of the server ended (install, update, restore…). */
    #[On('agent-task-finished')]
    #[On('game-refresh')]
    public function refresh(): void
    {
        $server = $this->server();
        $games = app(ManageGameServers::class);
        if (in_array($server->status, ['installing', 'failed'], true)) {
            $this->live = null;
        } else {
            try {
                $this->live = $games->status($server);
                $this->error = null;
            } catch (AgentException $e) {
                $this->live = null;
                $this->error = $e->getMessage();
            }
        }
        $this->sampleCpu();
        $state = $this->runState($server);
        $this->settlePending($state);
        if ($state !== $this->state) {
            $previous = $this->state;
            $this->state = $state;
            if ($previous !== null) {
                $this->dispatch('game-state-changed', server: $this->serverId, state: $state);
            }
        }
    }

    public function control(string $action): void
    {
        Gate::authorize('games');
        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            return;
        }
        try {
            app(ManageGameServers::class)->control($this->server(), $action);
            $this->pending = $action;
            $this->pendingSince = time();
        } catch (AgentException $e) {
            Toast::error(__('Akce selhala'), $e->getMessage());
        }
        $this->refresh();
    }

    /**
     * The state the page shows: the unit's, made finer. A game's unit is
     * "active" long before the world is loaded; it counts as running once
     * the agent finds it ready (its console answers).
     */
    private function runState(GameServer $server): string
    {
        $state = $server->runState($this->live);
        // The agent says so (ready); one from before that flag: once the players answer.
        if ($state === 'running' && ! ($this->live['ready'] ?? isset($this->live['players']))) {
            return 'starting';
        }

        return $state;
    }

    private function settlePending(string $state): void
    {
        if ($this->pending === null) {
            return;
        }
        $done = match ($this->pending) {
            'stop' => in_array($state, ['stopped', 'crashed'], true),
            'start' => in_array($state, ['running', 'crashed'], true),
            // A restart is over once the server runs again after it began.
            default => $state === 'crashed' || ($state === 'running' && (int) ($this->live['since'] ?? 0) >= (int) $this->pendingSince - 1),
        };
        if ($done || time() - (int) $this->pendingSince > self::PENDING_SECONDS) {
            $this->pending = $this->pendingSince = null;
        }
    }

    /** CPU load over the last poll, in % of one core (100 = a whole core). */
    private function sampleCpu(): void
    {
        $nsec = isset($this->live['cpu_nsec']) ? (int) $this->live['cpu_nsec'] : null;
        $now = (int) now()->getPreciseTimestamp(6) * 1000; // ns
        if ($nsec === null) {
            $this->cpuSample = $this->cpuPercent = null;

            return;
        }
        if ($this->cpuSample !== null && $nsec < $this->cpuSample['nsec']) {
            $this->cpuSample = null; // restarted: the counter began again
        }
        // Two samples a moment apart (a click and a tick) say nothing: keep the last load.
        if ($this->cpuSample !== null && $now - $this->cpuSample['at'] < 1_000_000_000) {
            return;
        }
        if ($this->cpuSample !== null) {
            $this->cpuPercent = round(($nsec - $this->cpuSample['nsec']) / ($now - $this->cpuSample['at']) * 100, 1);
        }
        $this->cpuSample = ['nsec' => $nsec, 'at' => $now];
    }

    /** Polls faster while something is about to change. */
    public function interval(): int
    {
        return $this->pending !== null || in_array($this->state, ['starting', 'stopping', 'installing'], true) ? 2000 : 5000;
    }

    public function render(): View
    {
        $server = $this->server();
        $state = $this->pending !== null && ! in_array($this->state, ['crashed'], true)
            ? ['start' => 'starting', 'stop' => 'stopping', 'restart' => 'starting'][$this->pending]
            : (string) $this->state;

        return view('livewire.games.status', [
            'server' => $server,
            'shown' => $state,
            'badge' => GameServer::runStateBadge($state),
            'interval' => $this->interval(),
        ]);
    }
}
