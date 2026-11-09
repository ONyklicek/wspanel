<?php

namespace App\Support;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Facades\Cache;

/**
 * Long agent jobs (installing a module, system updates) the panel does not
 * wait for: the job id is kept under a key, the page asks how it went.
 */
final class BackgroundJobs
{
    private const TTL = 6 * 3600;

    /**
     * @param  array{title: string, done: string, body?: string|null}  $text
     */
    public static function start(string $key, string $jobId, array $text): void
    {
        Cache::put(self::cacheKey($key), ['id' => $jobId, 'started' => now()->toIso8601String(), ...$text], self::TTL);
    }

    /** @return array{id: string, started: string, title: string, done: string, body?: string|null}|null */
    public static function running(string $key): ?array
    {
        return Cache::get(self::cacheKey($key));
    }

    /**
     * One look at the job: 'running', 'done' or 'failed' (with the error).
     * A job the agent no longer knows (it restarted) counts as done.
     *
     * @return array{status: string, error?: string|null}
     */
    public static function check(string $key): array
    {
        $job = self::running($key);
        if ($job === null) {
            return ['status' => 'done'];
        }
        try {
            $out = app(AgentClient::class)->call('job.status', ['id' => $job['id']]);
        } catch (AgentException $e) {
            if ($e->errorCode === 'not_found') {
                self::forget($key);

                return ['status' => 'done'];
            }

            return ['status' => 'running'];
        }
        if (($out['status'] ?? 'running') !== 'running') {
            self::forget($key);
        }

        return ['status' => $out['status'] ?? 'running', 'error' => $out['error'] ?? null];
    }

    public static function forget(string $key): void
    {
        Cache::forget(self::cacheKey($key));
    }

    private static function cacheKey(string $key): string
    {
        return 'agent-job:'.$key;
    }
}
