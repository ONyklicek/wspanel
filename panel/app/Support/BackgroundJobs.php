<?php

namespace App\Support;

use App\Models\AgentTask;

/**
 * A page's long agent jobs by name (installing a module, system updates):
 * AgentTasks under a key, so the page finds what it started and the top
 * bar's indicator shows it like any other.
 */
final class BackgroundJobs
{
    /**
     * @param  array<string, mixed>  $job  the agent's answer
     * @param  array{title: string, done: string, body?: string|null}  $text
     */
    public static function start(string $key, array $job, array $text, string $op = ''): AgentTask
    {
        return AgentTasks::start($job, $op, $text, key: $key);
    }

    /** @return array{id: int, started: string, title: string, done: string, body?: string|null}|null */
    public static function running(string $key): ?array
    {
        $task = self::task($key);

        return $task === null ? null : [
            'id' => $task->id, 'started' => $task->started_at->toIso8601String(),
            'title' => $task->title, 'done' => $task->done_title, 'body' => $task->body,
        ];
    }

    /**
     * One look at the job: 'running', 'done' or 'failed' (with the error) once
     * it ended and nobody was told yet, 'none' when there is nothing to say.
     *
     * @return array{status: string, error?: string|null}
     */
    public static function check(string $key): array
    {
        $task = AgentTask::query()->where('key', $key)
            ->where(fn ($q) => $q->running()->orWhereNull('notified_at'))->latest('id')->first();
        if ($task === null) {
            return ['status' => 'none'];
        }
        $task = AgentTasks::settle($task);
        if ($task->isRunning()) {
            return ['status' => 'running'];
        }
        if ($task->notified_at !== null) {
            return ['status' => 'none'];
        }
        AgentTasks::notify($task);

        return ['status' => $task->status, 'error' => $task->error];
    }

    private static function task(string $key): ?AgentTask
    {
        return AgentTask::query()->running()->where('key', $key)->latest('id')->first();
    }
}
