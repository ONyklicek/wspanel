<?php

namespace App\Models;

use App\Enums\Game;
use App\Models\Concerns\OwnedByClient;
use Illuminate\Database\Eloquent\Model;

/**
 * A game server (the optional Herní servery module). The agent keeps the
 * rest (RCON password, unit, files) under the same id.
 *
 * @property int $id
 * @property ?int $client_id
 * @property string $name
 * @property Game $game
 * @property string $flavor
 * @property string $version
 * @property ?string $build
 * @property int $port
 * @property int $memory_mb
 * @property int $cpu_percent
 * @property string $status installing | active | failed
 * @property ?string $error
 * @property bool $sftp
 * @property ?string $sftp_keys
 * @property bool $sftp_password
 */
class GameServer extends Model
{
    use OwnedByClient;

    protected $fillable = ['name', 'game', 'flavor', 'version', 'build', 'port', 'memory_mb', 'cpu_percent', 'status', 'error', 'sftp', 'sftp_keys', 'sftp_password'];

    protected function casts(): array
    {
        return ['game' => Game::class, 'port' => 'integer', 'memory_mb' => 'integer', 'cpu_percent' => 'integer', 'sftp' => 'boolean', 'sftp_password' => 'boolean'];
    }

    public function flavorLabel(): string
    {
        return $this->game->flavors()[$this->flavor] ?? $this->flavor;
    }

    /** "Paper 1.21.10 #130", "Factorio 2.1.24". */
    public function versionLabel(): string
    {
        $name = $this->game->is('minecraft') ? ucfirst($this->flavor) : $this->game->label();
        if ($this->version === 'latest') {
            // Steam: no versions, only builds.
            return trim($name.' '.($this->build ? __('build :build', ['build' => $this->build]) : __('nejnovější')));
        }

        return trim($name.' '.$this->version.($this->build ? ' #'.$this->build : ''));
    }

    public function address(): string
    {
        $host = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'server');

        return $this->game->connectHint($host, $this->port);
    }

    /** The server's own user: its files, its SFTP login. */
    public function systemUser(): string
    {
        return 'wsg_'.$this->id;
    }

    /** "wsg_7@panel.example.com" for an SFTP client. */
    public function sftpTarget(): string
    {
        return $this->systemUser().'@'.(config('panel.ssh_host') ?: parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'server');
    }

    /**
     * What the server does now, from its install status and the agent's unit
     * state: installing | failed | running | starting | stopping | crashed |
     * stopped | unknown (the agent did not answer).
     *
     * @param  array<string, mixed>|null  $live  ManageGameServers::liveState()
     */
    public function runState(?array $live): string
    {
        return match (true) {
            $this->status === 'installing' => 'installing',
            $this->status === 'failed' => 'failed',
            $live === null || ! isset($live['state']) => 'unknown',
            $live['state'] === 'active' => 'running',
            in_array($live['state'], ['activating', 'reloading'], true) => 'starting',
            $live['state'] === 'deactivating' => 'stopping',
            $live['state'] === 'failed' => 'crashed',
            default => 'stopped',
        };
    }

    /** @return array{label: string, color: string} */
    public static function runStateBadge(string $state): array
    {
        return match ($state) {
            'installing' => ['label' => __('instaluje se'), 'color' => 'warning'],
            'failed' => ['label' => __('instalace selhala'), 'color' => 'danger'],
            'running' => ['label' => __('běží'), 'color' => 'success'],
            'starting' => ['label' => __('spouští se'), 'color' => 'warning'],
            'stopping' => ['label' => __('zastavuje se'), 'color' => 'warning'],
            'crashed' => ['label' => __('spadl'), 'color' => 'danger'],
            'stopped' => ['label' => __('zastaveno'), 'color' => 'gray'],
            default => ['label' => __('neznámý'), 'color' => 'gray'],
        };
    }
}
