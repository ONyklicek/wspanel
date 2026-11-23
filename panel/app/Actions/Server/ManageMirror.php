<?php

namespace App\Actions\Server;

use App\Models\AgentTask;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\AgentTasks;
use Illuminate\Support\Facades\Cache;

/**
 * The mirror (Server → Zrcadlo): this server as the primary or the standby
 * of a pair. The agents do the work; the decision to fail over is the
 * standby agent's own, so it never waits for a panel.
 */
class ManageMirror
{
    /** Layers of the copy, in the order the standby syncs them: key => label. */
    public const LAYERS = [
        'version' => 'Verze WSPanelu',
        'state' => 'Stav a konfigurace',
        'accounts' => 'Uživatelé a skupiny',
        'software' => 'Software (enginy, PHP, moduly)',
        'sites' => 'Soubory webů',
        'mail' => 'Pošta (schránky)',
        'mariadb' => 'MariaDB (replikace)',
        'postgresql' => 'PostgreSQL (replikace)',
        'dumps' => 'MongoDB a vyhrazené DB (výpisy)',
        'kv' => 'Valkey / Redis (repliky)',
        'windns' => 'Windows DNS',
    ];

    public const ROLES = [
        '' => 'mimo zrcadlo',
        'primary' => 'primár',
        'standby' => 'zrcadlo (standby)',
        'fenced' => 'odstavený',
    ];

    private const CACHE = 'mirror.status';

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AgentException
     */
    public function status(): array
    {
        $status = $this->agent->call('mirror.status');
        Cache::put(self::CACHE, $status, 60);

        return $status;
    }

    /** The last known state (alerts, DNS follow-up): never throws. */
    public function cached(): array
    {
        return Cache::get(self::CACHE) ?? rescue(fn () => $this->status(), [], report: false);
    }

    public function role(): string
    {
        return (string) ($this->cached()['role'] ?? '');
    }

    /**
     * @param  array{address: string, peer_address: string, lan_address?: string, public_ipv4?: string, port?: int}  $data
     * @return array{code: string, expires: string}
     *
     * @throws AgentException
     */
    public function init(array $data): array
    {
        return $this->forget($this->agent->call('mirror.init', array_filter($data, fn ($v) => $v !== null && $v !== '')));
    }

    /** @throws AgentException */
    public function pairingCode(): array
    {
        return $this->agent->call('mirror.pairing_code');
    }

    /**
     * @param  array{primary: string, port?: int, code: string, address: string, lan_address?: string, public_ipv4?: string, local_port?: int}  $data
     *
     * @throws AgentException
     */
    public function join(array $data): array
    {
        return $this->forget($this->agent->call('mirror.join', array_filter($data, fn ($v) => $v !== null && $v !== '')));
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AgentException
     */
    public function settings(array $data): array
    {
        return $this->forget($this->agent->call('mirror.settings', $data));
    }

    /** @throws AgentException */
    public function promote(string $nodeId): AgentTask
    {
        $job = $this->agent->call('mirror.promote', ['confirm' => $nodeId]);

        return AgentTasks::start($job, 'mirror.promote', [
            'title' => __('Povýšení na primár'),
            'done' => __('Tento server je primár'),
            'body' => __('Weby běží odsud, DNS se přepíná.'),
        ], self::class.'@changed', key: 'mirror');
    }

    /** @throws AgentException */
    public function switchover(): AgentTask
    {
        $job = $this->agent->call('mirror.switchover');

        return AgentTasks::start($job, 'mirror.switchover', [
            'title' => __('Plánované přepnutí na zrcadlo'),
            'done' => __('Přepnuto: zrcadlo je primár'),
            'body' => __('Tento server je teď jeho zrcadlem.'),
        ], self::class.'@changed', key: 'mirror');
    }

    /** @throws AgentException */
    public function rejoin(): array
    {
        return $this->forget($this->agent->call('mirror.rejoin'));
    }

    /** @throws AgentException */
    public function unfence(): array
    {
        return $this->forget($this->agent->call('mirror.unfence'));
    }

    /** @throws AgentException */
    public function unpair(string $nodeId): array
    {
        return $this->forget($this->agent->call('mirror.unpair', ['confirm' => $nodeId]));
    }

    /** @throws AgentException */
    public function syncNow(): void
    {
        $this->agent->call('mirror.sync_now');
    }

    /**
     * @param  array{host: string, port?: int, user: string, zones: list<string>, ttl?: int, extra?: list<string>}  $data
     * @return array{public_key: string}
     *
     * @throws AgentException
     */
    public function winDnsSet(array $data): array
    {
        return $this->forget($this->agent->call('mirror.windns_set', $data));
    }

    /** @throws AgentException */
    public function winDnsTrust(): array
    {
        return $this->forget($this->agent->call('mirror.windns_trust'));
    }

    /** @throws AgentException */
    public function winDnsTest(): array
    {
        return $this->agent->call('mirror.windns_test');
    }

    /** @throws AgentException */
    public function winDnsRemove(): array
    {
        return $this->forget($this->agent->call('mirror.windns_remove'));
    }

    /** Task handler: the state is read again. */
    public function changed(AgentTask $task): void
    {
        Cache::forget(self::CACHE);
    }

    /**
     * @template T
     *
     * @param  T  $out
     * @return T
     */
    private function forget(mixed $out): mixed
    {
        Cache::forget(self::CACHE);

        return $out;
    }
}
