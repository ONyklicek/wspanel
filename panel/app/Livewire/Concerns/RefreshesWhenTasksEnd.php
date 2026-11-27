<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\On;

/**
 * A list whose row actions run in the background: drawn anew when a task
 * ends (the top bar's indicator says so), so a button that turned while its
 * task ran (ShowsRunningTask) stops turning at once, not at the next poll.
 */
trait RefreshesWhenTasksEnd
{
    #[On('agent-task-finished')]
    public function refreshWhenTaskEnds(): void {}
}
