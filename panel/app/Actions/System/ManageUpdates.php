<?php

namespace App\Actions\System;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/** System updates (apt), through the agent. */
class ManageUpdates
{
    public function __construct(
        private readonly AgentClient $agent,
    ) {}

    /**
     * @return array{packages: list<array{name: string, from: string, to: string, security: bool}>, reboot_required: bool, reboot_packages?: list<string>, auto_security: bool}
     *
     * @throws AgentException
     */
    public function status(bool $refresh = false): array
    {
        return $this->agent->call('sys.updates', $refresh ? ['refresh' => true] : []);
    }

    /**
     * Starts installing updates: all, the security ones, or only the named
     * packages. An agent job; the caller follows it.
     *
     * @param  list<string>  $packages
     * @return array<string, mixed> the job (job_id)
     *
     * @throws AgentException
     */
    public function upgrade(bool $securityOnly = false, array $packages = []): array
    {
        return $this->agent->call('sys.upgrade', match (true) {
            $packages !== [] => ['packages' => array_values($packages)],
            $securityOnly => ['security_only' => true],
            default => [],
        });
    }

    /** @throws AgentException */
    public function setAutoSecurity(bool $enabled): void
    {
        $this->agent->call('sys.auto_updates', ['enabled' => $enabled]);
    }

    /**
     * Upgrades WSPanel itself to $version: the agent starts it as a unit of
     * its own, which the panel's restart does not stop.
     *
     * @throws AgentException
     */
    public function upgradePanel(string $version): void
    {
        $this->agent->call('panel.upgrade', ['version' => $version]);
    }

    /**
     * The panel upgrade: none, running, done or failed, with its log.
     *
     * @return array{state: string, version?: string, started_at?: string, finished_at?: string, log: list<string>}
     *
     * @throws AgentException
     */
    public function panelUpgradeStatus(): array
    {
        return $this->agent->call('panel.upgrade_status');
    }
}
