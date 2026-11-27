<?php

namespace App\Support;

use App\Models\AgentTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What runs in the background now, for the buttons that started it: they
 * turn and wait until it ends. One query a request (a table asks per row),
 * forgotten whenever a task is saved, so a button rendered right after its
 * click already knows.
 */
final class RunningTasks
{
    /** @var Collection<int, AgentTask>|null */
    private static ?Collection $running = null;

    /**
     * Whether a task runs whose op or key is one of $ops (patterns such as
     * "game.*"; a page's background job is known by its key), for $subject
     * when given.
     *
     * @param  string|list<string>  $ops
     */
    public static function has(string|array $ops, ?Model $subject = null): bool
    {
        self::$running ??= AgentTask::query()->visibleTo(auth()->user())->running()
            ->get(['id', 'op', 'key', 'subject_type', 'subject_id']);

        return self::$running->contains(fn (AgentTask $task) => (Str::is((array) $ops, (string) $task->op) || ($task->key !== null && Str::is((array) $ops, $task->key)))
            && ($subject === null || ($task->subject_type === $subject->getMorphClass() && (string) $task->subject_id === (string) $subject->getKey())));
    }

    public static function forget(): void
    {
        self::$running = null;
    }
}
