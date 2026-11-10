<?php

namespace App\Actions\System;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Facades\Cache;

/**
 * The server itself, through the agent: restart, name, time, swap, processes,
 * disk usage, disk health and the network.
 */
class ManageSystem
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Restarts the server in $minutes, or at $at (HH:MM, the next one).
     *
     * @return string|null when, ISO 8601
     *
     * @throws AgentException
     */
    public function reboot(int $minutes = 1, ?string $at = null): ?string
    {
        return $this->agent->call('sys.reboot', $at !== null ? ['at' => $at] : ['in_minutes' => $minutes])['scheduled'] ?? null;
    }

    /** @throws AgentException */
    public function cancelReboot(): void
    {
        $this->agent->call('sys.reboot', ['cancel' => true]);
    }

    /** @throws AgentException */
    public function setAutoReboot(bool $enabled, ?string $time = null): void
    {
        $this->agent->call('sys.auto_reboot', array_filter(['enabled' => $enabled, 'time' => $time], fn ($v) => $v !== null));
    }

    /**
     * @return array{hostname: string, timezone: string, ntp: bool, ntp_available: bool, ntp_synchronized: bool, time: string, swaps: list<array{path: string, type: string, size: int, used: int, managed: bool}>, swappiness: int, swap_file: string, swap_file_size?: int, timezones?: list<string>}
     *
     * @throws AgentException
     */
    public function settings(bool $timezones = false): array
    {
        return $this->agent->call('sys.settings', $timezones ? ['timezones' => true] : []);
    }

    /** @throws AgentException */
    public function setHostname(string $hostname): void
    {
        $this->agent->call('sys.hostname', ['hostname' => $hostname]);
    }

    /** @throws AgentException */
    public function setTimezone(string $timezone): void
    {
        $this->agent->call('sys.timezone', ['timezone' => $timezone]);
        Cache::forget(self::TIMEZONE);
    }

    private const TIMEZONE = 'system.timezone';

    /**
     * The server's time zone (the clock in the top bar), asked once an hour;
     * the panel's when the agent does not answer.
     */
    public function timezone(): string
    {
        $zone = Cache::remember(self::TIMEZONE, 3600, fn () => rescue(fn () => (string) ($this->settings()['timezone'] ?? ''), '', report: false));

        return in_array($zone, timezone_identifiers_list(), true) ? $zone : (string) config('app.timezone');
    }

    /** @throws AgentException */
    public function setNtp(bool $enabled): void
    {
        $this->agent->call('sys.ntp', ['enabled' => $enabled]);
    }

    /**
     * The panel's swap file in MB (0 removes it) and/or vm.swappiness.
     *
     * @throws AgentException
     */
    public function setSwap(?int $sizeMb, ?int $swappiness = null): void
    {
        $this->agent->call('sys.swap', array_filter(['size_mb' => $sizeMb, 'swappiness' => $swappiness], fn ($v) => $v !== null));
    }

    /**
     * @return list<array{pid: int, ppid: int, user: string, name: string, cmd: string, state: string, cpu: float, rss: int, start: int, started: string, site?: string}>
     *
     * @throws AgentException
     */
    public function processes(): array
    {
        return $this->agent->call('sys.processes')['processes'] ?? [];
    }

    /** @throws AgentException */
    public function kill(int $pid, int $start, bool $force = false): void
    {
        $this->agent->call('sys.kill', ['pid' => $pid, 'start' => $start, 'signal' => $force ? 'KILL' : 'TERM']);
    }

    /**
     * @return array{path: string, total: int, entries: list<array{path: string, size: int}>, partial: bool}
     *
     * @throws AgentException
     */
    public function diskUsage(string $path): array
    {
        return $this->agent->call('sys.disk_usage', ['path' => $path]);
    }

    /**
     * @return array{installed: bool, disks: list<array<string, mixed>>}
     *
     * @throws AgentException
     */
    public function disks(): array
    {
        return $this->agent->call('sys.disks');
    }

    /**
     * @return array{interfaces: list<array{name: string, state: string, mac: string, mtu: int, addresses: list<string>}>, gateways: list<array{gateway: string, dev: string}>, resolvers: list<string>}
     *
     * @throws AgentException
     */
    public function network(): array
    {
        return $this->agent->call('sys.network');
    }
}
