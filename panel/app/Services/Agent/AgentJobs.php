<?php

namespace App\Services\Agent;

/** Waits for background jobs of async agent operations (dumps, restores). */
class AgentJobs
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Polls job.status until the job finishes or $seconds pass.
     *
     * @return array<string, mixed>|null the job output, or null while still running
     *
     * @throws AgentException when the job failed
     */
    public function await(string $jobId, float $seconds = 20.0): ?array
    {
        $deadline = microtime(true) + $seconds;
        do {
            $job = $this->agent->call('job.status', ['id' => $jobId]);
            if ($job['status'] === 'done') {
                return $job['output'] ?? [];
            }
            if ($job['status'] === 'failed') {
                throw new AgentException('job_failed', $job['error'] ?? __('Úloha selhala.'), $job['op'] ?? '');
            }
            usleep(500_000);
        } while (microtime(true) < $deadline);

        return null;
    }
}
