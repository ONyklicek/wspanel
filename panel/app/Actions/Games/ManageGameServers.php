<?php

namespace App\Actions\Games;

use App\Actions\Clients\ManageClients;
use App\Actions\Sites\ManageSsh;
use App\Enums\Game;
use App\Enums\SshAccess;
use App\Exceptions\LimitReachedException;
use App\Models\Client;
use App\Models\GameServer;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentJobs;
use App\Support\CurrentClient;
use App\Support\Limits;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Game servers, through the agent (games.go). */
class ManageGameServers
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly AgentJobs $jobs,
        private readonly Limits $limits,
    ) {}

    /**
     * Versions that can be installed now: game => flavor => versions.
     *
     * @return array<string, array<string, list<string>>>
     */
    public function catalog(): array
    {
        try {
            $out = Cache::remember('games.catalog', 600, fn () => $this->agent->call('games.catalog'));
        } catch (AgentException) {
            return [];
        }

        return array_intersect_key($out, array_flip(array_column(Game::cases(), 'value')));
    }

    /** @return list<string> */
    public function versions(Game $game, string $flavor): array
    {
        return $this->catalog()[$game->value][$flavor] ?? [];
    }

    /**
     * Creates the server: the record first, then the agent's install (Java,
     * the download, the unit); true when it runs, false while installing.
     *
     * @param  array{name: string, game: string, flavor: string, version: string, port?: ?int, memory_mb: int, cpu_percent?: ?int, eula?: bool, client_id?: ?int}  $data
     *
     * @throws AgentException
     * @throws LimitReachedException
     */
    public function create(array $data): GameServer
    {
        $owner = Client::find(CurrentClient::id() ?? ($data['client_id'] ?? null));
        $game = Game::from($data['game']);
        $memory = (int) $data['memory_mb'];
        $this->limits->assertCanAdd($owner, 'games');
        $this->limits->assertCanAdd($owner, 'game_memory', $memory);
        $this->limits->assertGameCpu($owner, (int) ($data['cpu_percent'] ?? 0));
        $port = (int) ($data['port'] ?? 0) ?: $this->freePort($game);

        $server = new GameServer([
            'name' => $data['name'], 'game' => $game, 'flavor' => $data['flavor'], 'version' => $data['version'],
            'port' => $port, 'memory_mb' => $memory, 'cpu_percent' => (int) ($data['cpu_percent'] ?? 0), 'status' => 'installing',
        ]);
        if ($owner !== null) {
            $server->client_id = $owner->id;
        }
        $server->save();

        $params = [
            'id' => $server->id, 'name' => $server->name, 'game' => $game->value, 'flavor' => $server->flavor,
            'version' => $server->version, 'port' => $port, 'memory_mb' => $memory, 'cpu_percent' => $server->cpu_percent,
            'eula' => (bool) ($data['eula'] ?? false),
        ];
        if ($owner !== null) {
            if ($owner->linux_group === null) {
                CurrentClient::unrestricted(fn () => app(ManageClients::class)->applyToServer($owner));
            }
            $params['group'] = $owner->fresh()->linux_group;
        }
        try {
            $job = $this->agent->call('game.create', $params);
            $out = isset($job['job_id']) ? $this->jobs->await($job['job_id'], 900) : $job;
        } catch (AgentException $e) {
            $server->delete();
            throw $e;
        }
        if ($out !== null) {
            $server->update(['status' => 'active', 'build' => ($out['build'] ?? '') ?: null]);
        }

        return $server;
    }

    /** The game's own port when free, else the next one in the games' range. */
    public function freePort(Game $game): int
    {
        $taken = GameServer::acrossClients()->pluck('port')->all();
        for ($p = $game->defaultPort(); in_array($p, $taken, true) || ! self::portAllowed($p); $p++);

        return $p;
    }

    /** The agent's rule: 20000-40000, not RCON's 27000-27999 nor a known service. */
    public static function portAllowed(int $port): bool
    {
        return $port >= 20000 && $port <= 40000 && ($port < 27000 || $port > 27999)
            && ! in_array($port, [25575, 27017, 27018, 27019, 28017, 33060, 32400], true);
    }

    /**
     * Unit state, memory, players.
     *
     * @return array<string, mixed>
     *
     * @throws AgentException
     */
    public function status(GameServer $server): array
    {
        $out = $this->agent->call('game.status', ['id' => $server->id]);
        if ($server->status === 'installing') {
            $server->update(['status' => 'active']);
        }

        return $out;
    }

    /** @throws AgentException */
    public function control(GameServer $server, string $action): void
    {
        $this->agent->call('game.action', ['id' => $server->id, 'action' => $action]);
    }

    /** @throws AgentException */
    public function command(GameServer $server, string $command): string
    {
        return (string) ($this->agent->call('game.command', ['id' => $server->id, 'command' => $command])['output'] ?? '');
    }

    /**
     * @return list<string>
     *
     * @throws AgentException
     */
    public function logs(GameServer $server, int $lines = 200): array
    {
        return $this->agent->call('game.logs', ['id' => $server->id, 'lines' => $lines])['lines'] ?? [];
    }

    /**
     * @param  array{name: string, memory_mb: int, cpu_percent?: ?int}  $data
     *
     * @throws AgentException
     * @throws LimitReachedException
     */
    public function updateSettings(GameServer $server, array $data): void
    {
        $memory = (int) $data['memory_mb'];
        if ($memory > $server->memory_mb) {
            $this->limits->assertCanAdd($server->client, 'game_memory', $memory - $server->memory_mb);
        }
        $this->limits->assertGameCpu($server->client, (int) ($data['cpu_percent'] ?? 0));
        $this->agent->call('game.settings', ['id' => $server->id, 'name' => $data['name'], 'memory_mb' => $memory, 'cpu_percent' => (int) ($data['cpu_percent'] ?? 0)]);
        $server->update(['name' => $data['name'], 'memory_mb' => $memory, 'cpu_percent' => (int) ($data['cpu_percent'] ?? 0)]);
    }

    /**
     * The newest build, or another (newer) version; null while still running.
     *
     * @return array<string, mixed>|null
     *
     * @throws AgentException
     */
    public function upgrade(GameServer $server, ?string $version = null): ?array
    {
        $job = $this->agent->call('game.update', array_filter(['id' => $server->id, 'version' => $version]));
        $out = isset($job['job_id']) ? $this->jobs->await($job['job_id'], 600) : $job;
        if ($out !== null && ($out['changed'] ?? false)) {
            $server->update(['version' => $out['version'], 'build' => ($out['build'] ?? '') ?: null]);
        }

        return $out;
    }

    /**
     * A backup now (copied off-site when set up); null while still running.
     *
     * @return array{file: string, size: int, offsite?: string, offsite_error?: string}|null
     *
     * @throws AgentException
     */
    public function backup(GameServer $server): ?array
    {
        $job = $this->agent->call('game.backup', ['id' => $server->id]);

        return isset($job['job_id']) ? $this->jobs->await($job['job_id'], 600) : $job;
    }

    /**
     * Copies off-site (empty when the storage is not set up).
     *
     * @return list<array{file: string, size: int, modified: string}>
     *
     * @throws AgentException
     */
    public function offsiteCopies(GameServer $server): array
    {
        try {
            return $this->agent->call('offsite.list', ['game' => $server->id])['objects'] ?? [];
        } catch (AgentException $e) {
            if ($e->errorCode === 'conflict') {
                return [];
            }
            throw $e;
        }
    }

    /** Downloads an off-site copy back beside the local backups. @throws AgentException */
    public function fetchOffsite(GameServer $server, string $file): void
    {
        $this->agent->call('offsite.fetch', ['game' => $server->id, 'file' => $file]);
    }

    /**
     * @return list<array{file: string, size: int, created_at: string}>
     *
     * @throws AgentException
     */
    public function backups(GameServer $server): array
    {
        return $this->agent->call('game.backups', ['id' => $server->id])['backups'] ?? [];
    }

    /** @throws AgentException */
    public function restore(GameServer $server, string $file): bool
    {
        $job = $this->agent->call('game.restore', ['id' => $server->id, 'file' => $file]);

        return ! isset($job['job_id']) || $this->jobs->await($job['job_id'], 600) !== null;
    }

    /**
     * server.properties as the server wrote it (secrets left out).
     *
     * @return array{exists: bool, properties: list<array{key: string, value: string, panel?: bool}>}
     *
     * @throws AgentException
     */
    public function properties(GameServer $server): array
    {
        return $this->agent->call('game.properties', ['id' => $server->id]);
    }

    /**
     * @param  array<string, string>  $values  only the changed keys
     *
     * @throws AgentException
     */
    public function setProperties(GameServer $server, array $values, bool $restart = false): void
    {
        $this->agent->call('game.set_properties', ['id' => $server->id, 'values' => $values] + ($restart ? ['restart' => true] : []));
    }

    /**
     * @return array{whitelist_on: bool, whitelist: list<array<string, mixed>>, ops: list<array<string, mixed>>, banned: list<array<string, mixed>>, banned_ips: list<array<string, mixed>>}
     *
     * @throws AgentException
     */
    public function playerLists(GameServer $server): array
    {
        return $this->agent->call('game.player_lists', ['id' => $server->id]);
    }

    /** One change through the console (the server runs); its answer. @throws AgentException */
    public function playerAction(GameServer $server, string $action, string $name = '', string $reason = ''): string
    {
        return (string) ($this->agent->call('game.player_action', array_filter(['id' => $server->id, 'action' => $action, 'name' => $name, 'reason' => $reason], fn ($v) => $v !== ''))['output'] ?? '');
    }

    /**
     * Plugins found in a catalogue (modrinth|hangar) for the server's version.
     *
     * @return list<array{source: string, project: string, name: string, description: string, author: string, downloads: int, icon?: string, url: string}>
     *
     * @throws AgentException
     */
    public function searchPlugins(GameServer $server, string $source, string $query): array
    {
        return $this->agent->call('game.plugin_search', ['id' => $server->id, 'source' => $source, 'query' => $query])['results'] ?? [];
    }

    /**
     * The jars in plugins/ (with source and version for the panel's own; $check: newer versions too).
     *
     * @return list<array{file: string, size?: int, source?: string, project?: string, name?: string, version?: string, update?: string}>
     *
     * @throws AgentException
     */
    public function plugins(GameServer $server, bool $check = false): array
    {
        return $this->agent->call('game.plugins', ['id' => $server->id] + ($check ? ['check' => true] : []))['plugins'] ?? [];
    }

    /**
     * Installs (or updates) a plugin and what it requires; null while running.
     *
     * @return array{installed: list<array{name: string, version: string, file: string}>, needs: list<string>}|null
     *
     * @throws AgentException
     */
    public function installPlugin(GameServer $server, string $source, string $project): ?array
    {
        $job = $this->agent->call('game.plugin_install', ['id' => $server->id, 'source' => $source, 'project' => $project]);

        return isset($job['job_id']) ? $this->jobs->await($job['job_id'], 300) : $job;
    }

    /** @throws AgentException */
    public function removePlugin(GameServer $server, string $file): void
    {
        $this->agent->call('game.plugin_remove', ['id' => $server->id, 'file' => $file]);
    }

    /**
     * Factorio's server-settings.json: the values the form changes, and which secrets are set.
     *
     * @return array{values: array<string, mixed>, secrets: array<string, bool>}
     *
     * @throws AgentException
     */
    public function factorioSettings(GameServer $server): array
    {
        return $this->agent->call('game.factorio_settings', ['id' => $server->id]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $secrets  set when given (token, game_password)
     * @param  list<string>  $clear  secrets to empty
     *
     * @throws AgentException
     */
    public function setFactorioSettings(GameServer $server, array $values, array $secrets = [], array $clear = [], bool $restart = false): void
    {
        $this->agent->call('game.factorio_set_settings', array_filter([
            'id' => $server->id, 'values' => $values, 'secrets' => $secrets, 'clear' => $clear, 'restart' => $restart,
        ], fn ($v) => $v !== [] && $v !== false));
    }

    /**
     * Mods of the portal for the server's Factorio, by name, title or summary.
     *
     * @return list<array{name: string, title: string, owner: string, summary: string, downloads_count: int}>
     *
     * @throws AgentException
     */
    public function searchMods(GameServer $server, string $query): array
    {
        return $this->agent->call('game.mod_search', ['id' => $server->id, 'query' => $query])['results'] ?? [];
    }

    /**
     * @return array{mods: list<array{file: string, name: string, version: string, size?: int, update?: string}>, account: bool}
     *
     * @throws AgentException
     */
    public function mods(GameServer $server, bool $check = false): array
    {
        return $this->agent->call('game.mods', ['id' => $server->id] + ($check ? ['check' => true] : []));
    }

    /**
     * @return array{installed: list<array{name: string, version: string, file: string}>}|null
     *
     * @throws AgentException
     */
    public function installMod(GameServer $server, string $name): ?array
    {
        $job = $this->agent->call('game.mod_install', ['id' => $server->id, 'name' => $name]);

        return isset($job['job_id']) ? $this->jobs->await($job['job_id'], 300) : $job;
    }

    /** @throws AgentException */
    public function removeMod(GameServer $server, string $file): void
    {
        $this->agent->call('game.mod_remove', ['id' => $server->id, 'file' => $file]);
    }

    /**
     * SFTP into the server's home (never a shell), with keys and/or a
     * password; a generated password is returned once, the panel keeps none.
     *
     * @throws AgentException
     * @throws LimitReachedException
     */
    public function setSftp(GameServer $server, bool $enabled, string $keys, string $passwordMode = 'keep', ?string $password = null): ?string
    {
        if ($enabled) {
            $this->limits->assertSsh($server->client, SshAccess::Sftp);
        }
        $lines = ManageSsh::parseKeys($keys);
        $params = ['id' => $server->id, 'enabled' => $enabled, 'keys' => $lines];
        $generated = null;
        if ($passwordMode === 'generate') {
            $generated = $password = Str::password(20, symbols: false);
            $passwordMode = 'set';
        }
        if (in_array($passwordMode, ['set', 'remove'], true)) {
            $params['password_mode'] = $passwordMode;
        }
        if ($passwordMode === 'set') {
            $params['password'] = (string) $password;
        }
        $out = $this->agent->call('game.sftp', $params);
        // Switched off, the keys stay in the panel for switching it on again.
        $server->update(['sftp' => $enabled, 'sftp_password' => (bool) ($out['password'] ?? false)]
            + ($enabled ? ['sftp_keys' => $lines === [] ? null : implode("\n", $lines)] : []));

        return $enabled ? $generated : null;
    }

    /** @throws AgentException */
    public function delete(GameServer $server): void
    {
        try {
            $this->agent->call('game.delete', ['id' => $server->id]);
        } catch (AgentException $e) {
            if ($e->errorCode !== 'not_found') {
                throw $e;
            }
        }
        $server->delete();
    }
}
