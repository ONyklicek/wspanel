<?php

namespace App\Livewire\Games;

use App\Actions\Games\ManageGameServers;
use App\Models\GameServer;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A game server's console: its log as it is written (followed every few
 * seconds) with the commands sent and their answers in between, in the order
 * they happened.
 */
class GameConsole extends Component
{
    private const LINES = 200;

    #[Locked]
    public int $serverId;

    public string $command = '';

    /** Follow the log as it is written. */
    public bool $follow = true;

    /** @var list<array{at: string, text: string}> */
    #[Locked]
    public array $lines = [];

    /** @var list<array{at: string, command: string, output: string}> */
    #[Locked]
    public array $sent = [];

    #[Locked]
    public bool $running = false;

    public ?string $error = null;

    public function mount(int $server): void
    {
        Gate::authorize('games');
        $this->serverId = $server;
        $live = app(ManageGameServers::class)->liveState($this->server());
        $this->running = ($live['state'] ?? '') === 'active';
        $this->tail();
    }

    public function server(): GameServer
    {
        return GameServer::query()->findOrFail($this->serverId);
    }

    /** The status above it saw the server start or stop. */
    #[On('game-state-changed')]
    public function stateChanged(int $server, string $state): void
    {
        if ($server !== $this->serverId) {
            return;
        }
        $this->running = $state === 'running';
        $this->tail();
    }

    public function tail(): void
    {
        if ($this->server()->status === 'installing') {
            return;
        }
        try {
            $this->lines = array_map(self::parse(...), app(ManageGameServers::class)->logs($this->server(), self::LINES));
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function send(): void
    {
        Gate::authorize('games');
        $this->validate(['command' => ['required', 'string', 'max:1000', 'not_regex:/[\r\n]/']], [], ['command' => __('příkaz')]);
        try {
            $out = app(ManageGameServers::class)->command($this->server(), $this->command);
            $this->sent[] = ['at' => now()->toIso8601String(), 'command' => $this->command, 'output' => $out];
            $this->sent = array_slice($this->sent, -30);
            $this->dispatch('game-command-sent', command: $this->command);
            $this->command = '';
        } catch (AgentException $e) {
            Toast::error(__('Příkaz se nepodařilo poslat'), $e->getMessage());
        }
        $this->tail();
    }

    public function clearSent(): void
    {
        $this->sent = [];
    }

    /**
     * One journal line ("2026-11-10T12:34:56+0100 host java[123]: text") as
     * its time and its text.
     *
     * @return array{at: string, text: string}
     */
    public static function parse(string $line): array
    {
        if (preg_match('/^(\d{4}-\d\d-\d\dT[\d:]+[+-]\d\d:?\d\d)\s+\S+\s+[^:\s]+:\s?(.*)$/', $line, $m)) {
            return ['at' => $m[1], 'text' => $m[2]];
        }

        return ['at' => '', 'text' => $line];
    }

    /**
     * The log and the commands as one stream, oldest first.
     *
     * @return list<array{kind: string, time: string, text: string, output?: string}>
     */
    public function entries(): array
    {
        $tz = (string) config('app.timezone');
        $items = [];
        $add = function (string $kind, string $at, int $order, array $item) use (&$items, $tz) {
            $when = $at === '' ? null : rescue(fn () => Carbon::parse($at), null, report: false);
            $items[] = $item + ['kind' => $kind, 'time' => $when?->setTimezone($tz)->format('H:i:s') ?? '', '_key' => [$when?->getTimestamp() ?? 0, $order]];
        };
        foreach ($this->lines as $i => $l) {
            $add('log', $l['at'], $i, ['text' => $l['text']]);
        }
        foreach ($this->sent as $i => $c) {
            $add('command', $c['at'], 100000 + $i, ['text' => $c['command'], 'output' => $c['output']]);
        }
        // A command goes after the log lines written up to the second it was sent.
        usort($items, fn ($a, $b) => $a['_key'] <=> $b['_key']);

        return array_map(fn ($i) => array_diff_key($i, ['_key' => true]), $items);
    }

    /** A line's colour: warnings yellow, errors red, players coming and going green. */
    public static function tone(string $text): string
    {
        return match (true) {
            (bool) preg_match('/\b(ERROR|SEVERE|FATAL)\b|Exception|Error:/', $text) => 'text-red-400',
            (bool) preg_match('/\bWARN(ING)?\b/', $text) => 'text-amber-300',
            (bool) preg_match('/joined the game|left the game|\[JOIN\]|\[LEAVE\]/', $text) => 'text-emerald-300',
            default => 'text-gray-200',
        };
    }

    /** @return list<string> */
    public function suggestions(): array
    {
        $game = $this->server()->game;

        return $game->consoleCommands() ?? match ($game->engine()) {
            'minecraft' => ['list', 'say ', 'save-all', 'time set day', 'weather clear', 'tps', 'whitelist add ', 'op '],
            'factorio' => ['/players', '/save', '/time', '/admins', '/promote ', '/kick '],
            default => [], // a package's own game says them itself (console.commands)
        };
    }

    public function render(): View
    {
        return view('livewire.games.console', [
            'server' => $this->server(),
            'entries' => $this->entries(),
        ]);
    }
}
