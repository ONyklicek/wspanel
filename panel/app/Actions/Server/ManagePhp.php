<?php

namespace App\Actions\Server;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentJobs;
use Illuminate\Support\Facades\Cache;

/**
 * PHP versions on the server and their extensions (apt packages from
 * packages.sury.org), plus what FrankenPHP has built in.
 */
class ManagePhp
{
    public function __construct(private readonly AgentClient $agent, private readonly AgentJobs $jobs) {}

    /**
     * @return array{versions: list<array{version: string, installed: bool, default: bool, extensions: list<string>, available: list<string>}>, installable: list<string>, frankenphp: array<string, mixed>}
     *
     * @throws AgentException
     */
    public function status(): array
    {
        return $this->agent->call('php.status');
    }

    /**
     * Installs or removes an extension; waits a while for apt.
     *
     * @return bool true when finished, false while it still runs in the background
     *
     * @throws AgentException
     */
    public function extension(string $version, string $extension, string $action): bool
    {
        $job = $this->agent->call('php.extension', ['version' => $version, 'extension' => $extension, 'action' => $action]);

        return $this->jobs->await($job['job_id'], 40) !== null;
    }

    /** @throws AgentException */
    public function install(string $version): bool
    {
        $job = $this->agent->call('php.install', ['version' => $version]);
        $done = $this->jobs->await($job['job_id'], 40) !== null;
        Cache::forget('agent.sys.versions');

        return $done;
    }
}
