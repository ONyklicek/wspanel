<?php

namespace App\Livewire\Concerns;

use App\Models\AgentTask;
use App\Support\AgentTasks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;

/**
 * A record's page shows what runs in the background for the record (a
 * backup, an install…), live, and reloads what changed when it ends.
 * Include partials.subject-tasks in the view.
 */
trait WatchesAgentTasks
{
    /** The record the page is about. */
    abstract protected function agentTaskSubject(): ?Model;

    /** Called once a task of the record ended (reload what it changed). */
    protected function agentTaskFinished(AgentTask $task): void {}

    /**
     * What runs for the record, and what ended without anyone told yet.
     *
     * @return Collection<int, AgentTask>
     */
    public function subjectTasks(bool $withEnded = false): Collection
    {
        $subject = $this->agentTaskSubject();
        if ($subject === null) {
            return collect();
        }

        return AgentTask::query()->visibleTo(auth()->user())
            ->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())
            ->where(fn ($q) => $withEnded ? $q->running()->orWhereNull('notified_at') : $q->running())
            ->latest('id')->get();
    }

    /** The poll of partials.subject-tasks: settles them as they end. */
    public function settleSubjectTasks(): void
    {
        foreach ($this->subjectTasks(withEnded: true) as $task) {
            $task = AgentTasks::settle($task);
            if (! $task->isRunning() && $task->notified_at === null) {
                AgentTasks::notify($task);
                $this->agentTaskFinished($task);
                $this->dispatch('agent-task-finished', key: $task->key, status: $task->status,
                    subjectType: $task->subject_type, subjectId: $task->subject_id);
            }
        }
    }

    /** Settled elsewhere (the top bar's indicator, the scheduler). */
    #[On('agent-task-finished')]
    public function subjectTaskSettled(?string $subjectType = null, mixed $subjectId = null): void
    {
        $subject = $this->agentTaskSubject();
        if ($subject === null || $subjectType !== $subject->getMorphClass() || (string) $subjectId !== (string) $subject->getKey()) {
            return;
        }
        $task = AgentTask::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)->latest('id')->first();
        if ($task !== null) {
            $this->agentTaskFinished($task);
        }
    }
}
