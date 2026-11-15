<?php

namespace App\Actions\Games;

use App\Actions\Databases\ManageDatabases;
use App\Models\Database;
use App\Models\GameServer;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * One database for a game server and a site: the game server (its plugins)
 * and the site each get a login of their own on it, through the agent's
 * db.link. Only a database of the game server's own client, on the shared
 * MariaDB server (a dedicated instance admits its site alone).
 */
final class ManageGameDatabases
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * The game server's logins, each with who else the database is shared with.
     *
     * @return list<array{name: string, user: string, password: string, host: string, port: int, jdbc: string, sites: list<array{domain: string, prefix: string}>}>
     *
     * @throws AgentException
     */
    public function links(GameServer $server): array
    {
        $out = [];
        foreach ($this->agent->call('db.links', ['game' => $server->id])['links'] ?? [] as $link) {
            $sites = collect($this->agent->call('db.links', ['name' => $link['name']])['links'] ?? [])
                ->filter(fn ($l) => isset($l['domain']))->map(fn ($l) => ['domain' => $l['domain'], 'prefix' => $l['prefix']])->values()->all();
            $out[] = $link + ['sites' => $sites];
        }

        return $out;
    }

    /**
     * The databases the game server may use: its client's, MariaDB, on the shared server.
     *
     * @return array<string, string> name => label
     */
    public function attachable(GameServer $server): array
    {
        return Database::acrossClients()->where('client_id', $server->client_id)->where('engine', 'mariadb')->whereNull('instance')
            ->with('site')->orderBy('name')->get()
            ->mapWithKeys(fn (Database $d) => [$d->name => $d->site ? $d->name.' ('.$d->site->domain.')' : $d->name])->all();
    }

    /**
     * The sites the database may be shared with: the game server's client's.
     *
     * @return array<string, string> domain => domain
     */
    public function sites(GameServer $server): array
    {
        return Site::acrossClients()->where('client_id', $server->client_id)->orderBy('domain')->pluck('domain', 'domain')->all();
    }

    /** @throws AgentException */
    public function attach(GameServer $server, string $name): void
    {
        $this->assertAttachable($server, $name);
        $this->agent->call('db.link', ['engine' => 'mariadb', 'name' => $name, 'game' => $server->id]);
    }

    /**
     * A new database of the game server's client, for the game server, and
     * shared with a site at once when one is given.
     *
     * @throws AgentException
     */
    public function create(GameServer $server, string $name, ?string $domain = null, string $prefix = 'GAME_DB'): Database
    {
        $database = app(ManageDatabases::class)->create('mariadb', $name, null, null, $server->client)['database'];
        $this->agent->call('db.link', ['engine' => 'mariadb', 'name' => $database->name, 'game' => $server->id]);
        if ($domain !== null && $domain !== '') {
            $this->share($server, $database->name, $domain, $prefix);
        }

        return $database;
    }

    /**
     * The database for a site too: its .env gets <prefix>_HOST, _PORT,
     * _DATABASE, _USERNAME, _PASSWORD; its own DB_* stays.
     *
     * @throws AgentException
     */
    public function share(GameServer $server, string $name, string $domain, string $prefix): void
    {
        $this->assertAttachable($server, $name);
        if (! array_key_exists($domain, $this->sites($server))) {
            throw new AgentException('invalid_params', __('Web :domain nepatří vlastníkovi herního serveru.', ['domain' => $domain]), 'panel');
        }
        $this->agent->call('db.link', ['engine' => 'mariadb', 'name' => $name, 'domain' => $domain, 'prefix' => $prefix]);
    }

    /** A new password for the game server's login. @throws AgentException */
    public function newPassword(GameServer $server, string $name): void
    {
        $this->attach($server, $name);
    }

    /** @throws AgentException */
    public function detach(GameServer $server, string $name): void
    {
        $this->agent->call('db.unlink', ['engine' => 'mariadb', 'name' => $name, 'game' => $server->id]);
    }

    /** @throws AgentException */
    public function unshare(GameServer $server, string $name, string $domain): void
    {
        $this->assertAttachable($server, $name);
        $this->agent->call('db.unlink', ['engine' => 'mariadb', 'name' => $name, 'domain' => $domain]);
    }

    private function assertAttachable(GameServer $server, string $name): void
    {
        if (! array_key_exists($name, $this->attachable($server))) {
            throw new AgentException('invalid_params', __('Databáze :name nepatří vlastníkovi herního serveru, nebo není na sdíleném serveru MariaDB.', ['name' => $name]), 'panel');
        }
    }
}
