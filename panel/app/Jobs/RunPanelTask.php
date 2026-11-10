<?php

namespace App\Jobs;

use App\Models\AgentTask;
use App\Services\Agent\AgentException;
use App\Support\AgentTasks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * A panel operation of several agent steps (a staging copy, an import, an
 * app install), run by the queue worker (panel-queue) for its AgentTask: the
 * person who started it continues; the task says how it went.
 *
 * The operation is a method of an action class, called with the arguments
 * given (models are serialized by key and loaded fresh). What it returns
 * (an array) becomes the task's output; a returned ['message' => …,
 * 'body' => …] says how it ended.
 */
class RunPanelTask implements ShouldQueue
{
    use Queueable;

    /** One try: the operation is not repeated behind the person's back. */
    public int $tries = 1;

    public int $timeout = 3600;

    /**
     * @param  list<mixed>  $arguments
     */
    public function __construct(public int $taskId, public string $class, public string $method, public array $arguments) {}

    public function handle(): void
    {
        $task = AgentTask::find($this->taskId);
        if ($task === null || ! $task->isRunning()) {
            return;
        }
        // As the person who started it: a client's operation stays within the
        // client's records and package limits, as it would on the page.
        if ($task->user !== null) {
            Auth::setUser($task->user);
        }
        try {
            $out = app($this->class)->{$this->method}(...$this->arguments);
            AgentTasks::finishPanelTask($task, true, is_array($out) ? $out : []);
        } catch (Throwable $e) {
            AgentTasks::finishPanelTask($task, false, [], $e->getMessage());
            if (! $e instanceof AgentException) {
                report($e);
            }
        }
    }

    public function failed(?Throwable $e): void
    {
        $task = AgentTask::find($this->taskId);
        if ($task !== null && $task->isRunning()) {
            AgentTasks::finishPanelTask($task, false, [], $e?->getMessage() ?? __('Úloha selhala.'));
        }
    }
}
