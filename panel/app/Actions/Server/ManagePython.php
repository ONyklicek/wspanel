<?php

namespace App\Actions\Server;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentJobs;
use Illuminate\Support\Facades\Cache;

/**
 * Python for Python sites: not on a server until asked for (Server → Moduly
 * or install.sh --python=). The agent brings uv and the chosen CPython build.
 */
class ManagePython
{
    public function __construct(private readonly AgentClient $agent, private readonly AgentJobs $jobs) {}

    /**
     * @return bool true when finished, false while it still runs in the background
     *
     * @throws AgentException
     */
    public function install(string $version): bool
    {
        $job = $this->agent->call('python.install', ['version' => $version]);
        $done = $this->jobs->await($job['job_id'], 120) !== null;
        Cache::forget('agent.sys.versions');

        return $done;
    }

    /**
     * Moves an installed minor version to its newest patch release (security
     * fixes). The agent restarts the sites on it onto the new interpreter and
     * removes the old build.
     *
     * @return array<string, mixed>|null the agent's result (from, to, changed, restarted), null while it still runs
     *
     * @throws AgentException
     */
    public function upgrade(string $version): ?array
    {
        $job = $this->agent->call('python.upgrade', ['version' => $version]);
        $result = $this->jobs->await($job['job_id'], 120);
        Cache::forget('agent.sys.versions');

        return $result;
    }
}
