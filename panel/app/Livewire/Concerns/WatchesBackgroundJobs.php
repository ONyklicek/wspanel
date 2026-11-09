<?php

namespace App\Livewire\Concerns;

use App\Support\BackgroundJobs;
use App\Support\Toast;

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
        if (! is_array($job) || ! isset($job['job_id'])) {
            Toast::success($text['done'], $text['body'] ?? null);
            $this->backgroundJobFinished($key, true);

            return;
        }
        BackgroundJobs::start($key, (string) $job['job_id'], $text);
        Toast::success(__('Spuštěno na pozadí'), __(':title: můžete pokračovat v práci, výsledek se tu ukáže.', ['title' => $text['title']]));
        $this->loadBackgroundJobs();
    }

    public function pollBackgroundJobs(): void
    {
        foreach ($this->backgroundJobKeys() as $key) {
            $job = BackgroundJobs::running($key);
            if ($job === null) {
                continue;
            }
            $state = BackgroundJobs::check($key);
            if ($state['status'] === 'running') {
                continue;
            }
            $ok = $state['status'] === 'done';
            $ok ? Toast::success($job['done'], $job['body'] ?? null) : Toast::error(__(':title selhalo', ['title' => $job['title']]), $state['error'] ?? null);
            $this->backgroundJobFinished($key, $ok);
        }
        $this->loadBackgroundJobs();
    }
}
