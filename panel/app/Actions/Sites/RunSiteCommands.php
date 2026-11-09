<?php

namespace App\Actions\Sites;

use App\Enums\SshAccess;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\Limits;

/** The site's console: quick commands and commands of one's own, as the site user. */
class RunSiteCommands
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * @return array{commands: list<array{id: string, label: string}>, runs: list<array<string, mixed>>}
     *
     * @throws AgentException
     */
    public function overview(Site $site): array
    {
        return $this->agent->call('site.quick_commands', ['domain' => $site->domain]);
    }

    /**
     * A command line of one's own runs what SSH would: allowed to the
     * administrator, and to a client whose package allows a shell.
     */
    public function mayRunCustom(Site $site): bool
    {
        $client = Limits::forCurrentUser();

        return $client === null || $client->sshLimit() === SshAccess::Shell;
    }

    /** @throws AgentException */
    public function run(Site $site, string $command): string
    {
        return (string) $this->agent->call('site.exec', ['domain' => $site->domain, 'command' => $command])['run'];
    }

    /** @throws AgentException */
    public function runCustom(Site $site, string $line): string
    {
        if (! $this->mayRunCustom($site)) {
            throw new AgentException('forbidden', __('Vlastní příkazy balíček nepovoluje (jen SSH s shellem).'), 'site.exec');
        }

        return (string) $this->agent->call('site.exec', ['domain' => $site->domain, 'custom' => $line])['run'];
    }

    /**
     * @return array{output: string, offset: int, done: bool, exit: ?int, command: string}
     *
     * @throws AgentException
     */
    public function output(Site $site, string $run, int $offset = 0): array
    {
        return $this->agent->call('site.exec_output', ['domain' => $site->domain, 'run' => $run, 'offset' => $offset]);
    }
}
