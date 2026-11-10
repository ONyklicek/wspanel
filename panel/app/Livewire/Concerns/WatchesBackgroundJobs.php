<?php

namespace App\Livewire\Concerns;

use App\Support\BackgroundJobs;
use Livewire\Attributes\On;

/**
 * A page that starts long agent jobs and shows them running: the page stays
 * usable, polls the job and says how it ended. Include
 * partials.background-jobs in the view.
 */
trait WatchesBackgroundJobs
{
    /** @var array<string, array<string, mixed>> key => what runs (for the view) */
    public array $backgroundJobs = [];

    /** @return list<string> the keys of the jobs this page starts */
    abstract protected function backgroundJobKeys(): array;

    /** Called once a job of this page finished (reload what it changed). */
    protected function backgroundJobFinished(string $key, bool $ok): void {}

    public function mountWatchesBackgroundJobs(): void
    {
        $this->loadBackgroundJobs();
    }

    public function loadBackgroundJobs(): void
    {
        $this->backgroundJobs = [];
        foreach ($this->backgroundJobKeys() as $key) {
            if ($job = BackgroundJobs::running($key)) {
                $this->backgroundJobs[$key] = $job;
            }
        }
    }

    /**
     * @param  array{title: string, done: string, body?: string|null}  $text
     */
    protected function startBackgroundJob(string $key, mixed $job, array $text): void
    {
        $task = BackgroundJobs::start($key, is_array($job) ? $job : [], $text);
        if (! $task->isRunning()) {
            $this->backgroundJobFinished($key, $task->status === 'done');
        }
        $this->loadBackgroundJobs();
    }

    /** A job of this page settled by someone else (the top bar's indicator, the scheduler). */
    #[On('agent-task-finished')]
    public function backgroundJobSettled(?string $key = null, string $status = 'done'): void
    {
        if ($key !== null && in_array($key, $this->backgroundJobKeys(), true)) {
            $this->backgroundJobFinished($key, $status === 'done');
        }
        $this->loadBackgroundJobs();
    }

    public function pollBackgroundJobs(): void
    {
        foreach ($this->backgroundJobKeys() as $key) {
            $state = BackgroundJobs::check($key);
            if (in_array($state['status'], ['running', 'none'], true)) {
                continue;
            }
            // BackgroundJobs::check told the person (once, whoever noticed first).
            $this->backgroundJobFinished($key, $state['status'] === 'done');
        }
        $this->loadBackgroundJobs();
    }
}
