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
        'settings' => 'Nastavení obou serverů',
        'vip' => 'Plovoucí IP',
        'windns' => 'Windows DNS',
    ];

    public const ROLES = [
        '' => 'mimo zrcadlo',
        'primary' => 'primár',
        'standby' => 'zrcadlo (standby)',
        'fenced' => 'odstavený',
    ];

    private const CACHE = 'mirror.status';

    private const CACHE_FAILED = 'mirror.status.failed';

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

    /**
     * The last known state (alerts, DNS follow-up, the banner on every page):
     * never throws. A failed read is remembered for half a minute too, so an
     * unreachable agent costs one attempt, not one per page.
     */
    public function cached(): array
    {
        $state = Cache::get(self::CACHE);
        if (is_array($state)) {
            return $state;
        }
        if (Cache::has(self::CACHE_FAILED)) {
            return [];
        }
        try {
            return $this->status();
        } catch (\Throwable) {
            Cache::put(self::CACHE_FAILED, true, 30);

            return [];
        }
    }

    public function role(): string
    {
        return (string) ($this->cached()['role'] ?? '');
    }

    /**
     * Sites, databases and mail are created where they are served: on a
     * mirror's standby (or a fenced node) the agent refuses them anyway, and
     * this says so before the panel stores anything.
     *
     * @throws AgentException
     */
    public function assertServes(): void
    {
        if (in_array($this->role(), ['standby', 'fenced'], true)) {
            $url = $this->activePanelUrl();
            throw new AgentException('conflict', __('Tento server je zrcadlo: weby, databáze a poštu zakládejte na aktivním serveru:').' '.($url ?: __('v jeho panelu')), 'panel');
        }
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
    public function promote(string $nodeId, bool $allowVersionMismatch = false): AgentTask
    {
        $job = $this->agent->call('mirror.promote', ['confirm' => $nodeId] + ($allowVersionMismatch ? ['allow_version_mismatch' => true] : []));

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

    /**
     * join() as a background task (RunPanelTask).
     *
     * @param  array<string, mixed>  $data
     * @return array{message: string, body: string}
     *
     * @throws AgentException
     */
    public function joinQueued(array $data): array
    {
        $out = $this->join($data);

        return ['message' => __('Připojeno jako zrcadlo'), 'body' => (string) ($out['warning'] ?? __('První synchronizace začíná.'))];
    }

    /**
     * winDnsTest() as a background task (RunPanelTask): an SSH round trip to Windows.
     *
     * @return array{message: string, body: string}
     *
     * @throws AgentException
     */
    public function winDnsTestQueued(): array
    {
        $out = $this->winDnsTest();

        return ['message' => __('Windows DNS odpovídá'), 'body' => __('Zóna :zone: :n záznamů A/AAAA', ['zone' => $out['zone'], 'n' => count($out['records'] ?? [])])];
    }

    /**
     * The floating IP the serving node holds (the same on both servers).
     *
     * @param  array{address: string, interface?: string}  $data
     *
     * @throws AgentException
     */
    public function vipSet(array $data): array
    {
        return $this->forget($this->agent->call('mirror.vip_set', array_filter($data, fn ($v) => $v !== null && $v !== '')));
    }

    /** @throws AgentException */
    public function vipRemove(): array
    {
        return $this->forget($this->agent->call('mirror.vip_remove'));
    }

    /**
     * This server's interfaces with IPv4 networks: name => "eth0 (192.168.1.10/24)".
     *
     * @return array<string, string>
     */
    public function interfaces(): array
    {
        $out = [];
        foreach ((array) rescue(fn () => $this->agent->call('mirror.interfaces'), [], report: false) as $i) {
            $out[(string) $i['name']] = $i['name'].' ('.implode(', ', (array) ($i['addresses'] ?? [])).')';
        }

        return $out;
    }

    /**
     * The serving node's panel, seen from a node that does not serve (its
     * pages say where the sites are managed). Null when this node serves.
     */
    public function activePanelUrl(): ?string
    {
        $state = $this->cached();
        if (! in_array($state['role'] ?? '', ['standby', 'fenced'], true)) {
            return null;
        }
        $peer = (array) ($state['status']['peer'] ?? []);
        $url = (string) ($peer['panel_url'] ?? '');

        // Only an https address from the other node goes into a link.
        return ($peer['role'] ?? '') === 'primary' && str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
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
        Cache::forget(self::CACHE_FAILED);

        return $out;
    }
}
