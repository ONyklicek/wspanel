<?php

namespace App\Actions\System;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentJobs;

/** System updates (apt), through the agent. */
class ManageUpdates
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly AgentJobs $jobs,
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
     * Installs the updates; true when done, false while still running.
     *
     * @throws AgentException
     */
    public function upgrade(bool $securityOnly): bool
    {
        $job = $this->agent->call('sys.upgrade', $securityOnly ? ['security_only' => true] : []);

        return isset($job['job_id']) ? $this->jobs->await($job['job_id'], 900) !== null : true;
    }

    /** @throws AgentException */
    public function setAutoSecurity(bool $enabled): void
    {
        $this->agent->call('sys.auto_updates', ['enabled' => $enabled]);
    }
}
