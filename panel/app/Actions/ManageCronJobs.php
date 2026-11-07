<?php

namespace App\Actions;

use App\Models\CronJob;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

class ManageCronJobs
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly SyncCertificatesAndTasks $sync,
    ) {}

    /**
     * @param  array{kind: string, command: string, schedule: string}  $data
     *
     * @throws AgentException
     */
    public function create(Site $site, array $data): void
    {
        $this->agent->call('cron.add', $this->params($site, $data));
        $this->sync->tasks($site);
    }

    /**
     * @param  array{kind: string, command: string, schedule: string}  $data
     *
     * @throws AgentException
     */
    public function update(CronJob $job, array $data): void
    {
        $this->agent->call('cron.edit', $this->params($job->site, $data) + ['id' => $job->agent_id]);
        $this->sync->tasks($job->site);
    }

    /** @throws AgentException */
    public function delete(CronJob $job): void
    {
        $this->agent->call('cron.remove', ['domain' => $job->site->domain, 'id' => $job->agent_id]);
        $job->delete();
    }

    /**
     * @param  array{kind: string, command: string, schedule: string}  $data
     * @return array<string, mixed>
     */
    private function params(Site $site, array $data): array
    {
        return [
            'domain' => $site->domain,
            'kind' => $data['kind'],
            'args' => CronJob::splitArgs($data['command']),
            'schedule' => trim(preg_replace('/\s+/', ' ', $data['schedule'])),
        ];
    }
}
