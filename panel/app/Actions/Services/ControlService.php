<?php

namespace App\Actions\Services;

use App\Models\Service;
use App\Services\Agent\AgentClient;
use InvalidArgumentException;

class ControlService
{
    public const ACTIONS = ['start', 'stop', 'restart', 'reload', 'reset'];

    public function __construct(
        private readonly AgentClient $agent,
        private readonly SyncServices $sync,
    ) {}

    /** Runs service.<action> through the agent, then refreshes the mirror. */
    public function handle(Service $service, string $action): void
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException("Unknown service action {$action}");
        }

        try {
            $this->agent->call("service.{$action}", $service->agentParams());
        } finally {
            // Refresh even on failure: the unit may have changed state anyway.
            rescue(fn () => $this->sync->handle(), report: false);
        }
    }
}
