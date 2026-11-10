<?php

namespace App\Livewire;

use App\Models\AgentTask;
use App\Support\AgentTasks;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The top bar's "what runs in the background": the person's tasks (an
 * administrator's: all), live while any runs. It settles them as they end,
 * says how they ended (once) and tells the page (agent-task-finished), so the
 * page shows the new state without a reload.
 */
class TaskIndicator extends Component
{
    public function mount(): void
    {
        $this->settle(); // tasks that ended while nobody watched (the scheduler settled them)
    }

    #[On('agent-task-started')]
    public function started(): void {}

    /** Asks the agent about each running task; called by the poll. */
    public function settle(): void
    {
        foreach ($this->visible()->get() as $task) {
            $was = $task->isRunning();
            $task = AgentTasks::settle($task);
            if ($task->user_id === auth()->id() || $task->user_id === null) {
                AgentTasks::notify($task);
            }
            if ($was && ! $task->isRunning()) {
                $this->dispatch('agent-task-finished', key: $task->key, status: $task->status,
                    subjectType: $task->subject_type, subjectId: $task->subject_id);
            }
        }
    }

    /** @return Builder<AgentTask> running ones and the person's unannounced ones */
    private function visible()
    {
        $user = auth()->user();

        return AgentTask::query()->visibleTo($user)->where(fn ($q) => $q->running()
            ->orWhere(fn ($q) => $q->whereNull('notified_at')->where('user_id', $user?->id)));
    }

    /** @return Collection<int, AgentTask> */
    public function recent(): Collection
    {
        return AgentTask::query()->visibleTo(auth()->user())
            ->where(fn ($q) => $q->running()->orWhere('finished_at', '>=', now()->subMinutes(30)))
            ->latest('id')->limit(8)->get();
    }

    public function render(): View
    {
        $recent = $this->recent();

        return view('livewire.task-indicator', [
            'recent' => $recent,
            'running' => $recent->filter(fn (AgentTask $t) => $t->isRunning())->count(),
        ]);
    }
}
