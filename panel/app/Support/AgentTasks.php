<?php

namespace App\Support;

use App\Jobs\RunPanelTask;
use App\Models\AgentTask;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use Livewire\Livewire;
use Throwable;

/**
 * Long agent operations never keep the person waiting: the agent runs them as
 * a job, the panel keeps an AgentTask and returns at once. Whoever looks first
 * settles it — the task indicator in the top bar (live, every few seconds),
 * a page watching it, or the scheduler (every minute, nobody on the page) —
 * and runs its handler exactly once (the record's new state, a cache to drop).
 *
 * A handler is "Class@method", resolved from the container and called with
 * the finished task: $task->status is done or failed, $task->output the job's
 * result, $task->subject the record it is about.
 */
final class AgentTasks
{
    /**
     * Starts watching an agent job (what an async operation answered). An
     * answer without a job (a synchronous operation, the agent's dry run)
     * finishes the task at once.
     *
     * @param  array<string, mixed>  $job  the agent's answer
     * @param  array{title: string, done: string, body?: string|null}  $text
     */
    public static function start(array $job, string $op, array $text, ?string $handler = null, ?Model $subject = null, ?string $key = null): AgentTask
    {
        $task = AgentTask::create([
            'key' => $key,
            'op' => $op,
            'job_id' => isset($job['job_id']) ? (string) $job['job_id'] : null,
            'status' => AgentTask::RUNNING, // in the returned model too, not only in the database
            'title' => $text['title'],
            'done_title' => $text['done'],
            'body' => $text['body'] ?? null,
            'handler' => $handler,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'user_id' => auth()->id(),
            'started_at' => now(),
        ]);
        if ($task->job_id === null) {
            self::finish($task, AgentTask::DONE, $job, null);
            self::notify($task->refresh());
            // The page that started it shows the result at once (WatchesAgentTasks).
            $component = Livewire::current();
            if ($component instanceof Component && method_exists($component, 'subjectTaskSettled')) {
                $component->subjectTaskSettled($task->subject_type, $task->subject_id);
            }

            return $task;
        }
        if (Livewire::current() instanceof Component) {
            Toast::success(__('Spuštěno na pozadí'), __(':title – můžete pokračovat v práci, stav je vpravo nahoře.', ['title' => $task->title]));
            Livewire::current()->dispatch('agent-task-started');
        }

        return $task;
    }

    /**
     * A panel operation of several agent steps, run by the queue worker
     * (RunPanelTask): class and method of an action, with its arguments.
     *
     * @param  array{title: string, done: string, body?: string|null}  $text
     * @param  list<mixed>  $arguments
     */
    public static function queue(string $op, array $text, string $class, string $method, array $arguments = [], ?Model $subject = null, ?string $key = null): AgentTask
    {
        $task = AgentTask::create([
            'key' => $key, 'op' => $op, 'job_id' => null, 'status' => AgentTask::RUNNING, 'title' => $text['title'], 'done_title' => $text['done'],
            'body' => $text['body'] ?? null, 'subject_type' => $subject?->getMorphClass(), 'subject_id' => $subject?->getKey(),
            'user_id' => auth()->id(), 'started_at' => now(),
        ]);
        if (Livewire::current() instanceof Component) {
            Toast::success(__('Spuštěno na pozadí'), __(':title – můžete pokračovat v práci, stav je vpravo nahoře.', ['title' => $task->title]));
            Livewire::current()->dispatch('agent-task-started');
        }
        RunPanelTask::dispatch($task->id, $class, $method, $arguments);

        return $task->refresh();
    }

    /**
     * How a queued panel operation ended (RunPanelTask). Its output may say
     * how: ['message' => …, 'body' => …].
     *
     * @param  array<string, mixed>  $output
     */
    public static function finishPanelTask(AgentTask $task, bool $ok, array $output, ?string $error = null): void
    {
        $task->forceFill(array_filter([
            'status' => $ok ? AgentTask::DONE : AgentTask::FAILED,
            'output' => $output,
            'error' => $error,
            'done_title' => is_string($output['message'] ?? null) ? $output['message'] : null,
            'body' => is_string($output['body'] ?? null) ? $output['body'] : null,
            'finished_at' => now(),
        ], fn ($v) => $v !== null))->save();
    }

    /** One look at a running task; settles it when the job ended. */
    public static function settle(AgentTask $task): AgentTask
    {
        if ($task->status !== AgentTask::RUNNING || $task->job_id === null) {
            return $task;
        }
        try {
            $job = app(AgentClient::class)->call('job.status', ['id' => $task->job_id]);
        } catch (AgentException $e) {
            if ($e->errorCode !== 'not_found') {
                return $task; // the agent is restarting: ask again later
            }
            // The agent restarted during the job (an update of the agent does).
            $job = ['status' => 'done', 'output' => [], 'lost' => true];
        }
        $status = (string) ($job['status'] ?? 'running');
        if ($status === 'running') {
            return $task;
        }
        // Claimed by one settler only (the indicator and the scheduler may ask at once).
        $claimed = AgentTask::query()->whereKey($task->id)->where('status', AgentTask::RUNNING)->update(['status' => AgentTask::SETTLING]);
        if ($claimed !== 1) {
            return $task->refresh();
        }
        $task->status = AgentTask::SETTLING;
        $output = is_array($job['output'] ?? null) ? $job['output'] : [];
        if ($job['lost'] ?? false) {
            $output['lost'] = true;
        }
        self::finish($task, $status === 'done' ? AgentTask::DONE : AgentTask::FAILED, $output, $status === 'done' ? null : (string) AgentMessages::cs((string) ($job['error'] ?? __('Úloha selhala.'))));

        return $task->refresh();
    }

    /** Settles every running task (the scheduler, every minute). */
    public static function settleAll(): void
    {
        AgentTask::query()->running()->where('status', AgentTask::RUNNING)->get()->each(fn (AgentTask $t) => self::settle($t));
        // Settling interrupted half-way (a deploy restarted the panel): once more.
        AgentTask::query()->where('status', AgentTask::SETTLING)->where('updated_at', '<', now()->subMinutes(5))
            ->update(['status' => AgentTask::RUNNING]);
        // A panel operation whose worker was killed (power loss) ends nowhere.
        AgentTask::query()->where('status', AgentTask::RUNNING)->whereNull('job_id')->where('started_at', '<', now()->subHours(2))
            ->update(['status' => AgentTask::FAILED, 'error' => __('Úloha byla přerušena (restart serveru nebo panelu); zkontrolujte výsledek.'), 'finished_at' => now()]);
        AgentTask::query()->whereNotNull('finished_at')->where('finished_at', '<', now()->subDays(7))->delete();
    }

    /**
     * Waits for a task (a console command: nobody to show it to); the task
     * as it is then, finished or not.
     */
    public static function wait(AgentTask $task, float $seconds): AgentTask
    {
        $deadline = microtime(true) + $seconds;
        while ($task->isRunning() && microtime(true) < $deadline) {
            usleep(500_000);
            $task = self::settle($task->refresh());
        }

        return $task;
    }

    /** Tells the person who started it how it ended (once), and the open page. */
    public static function notify(AgentTask $task): void
    {
        if ($task->isRunning() || $task->notified_at !== null) {
            return;
        }
        if (AgentTask::query()->whereKey($task->id)->whereNull('notified_at')->update(['notified_at' => now()]) !== 1) {
            return;
        }
        if ($task->status === AgentTask::DONE) {
            Toast::success($task->done_title, ($task->output['lost'] ?? false)
                ? __('Agent se během úlohy restartoval; výsledek zkontrolujte.')
                : $task->body);
        } else {
            Toast::error(__(':title selhalo', ['title' => $task->title]), $task->error);
        }
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private static function finish(AgentTask $task, string $status, array $output, ?string $error): void
    {
        $task->forceFill(['status' => $status, 'output' => $output, 'error' => $error, 'finished_at' => now()])->save();
        if ($task->handler === null) {
            return;
        }
        [$class, $method] = explode('@', $task->handler, 2) + [1 => ''];
        try {
            app($class)->{$method}($task);
        } catch (Throwable $e) {
            report($e);
            $task->forceFill(['status' => AgentTask::FAILED, 'error' => $e->getMessage()])->save();
        }
    }
}
