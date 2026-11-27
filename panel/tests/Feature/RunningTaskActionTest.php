<?php

namespace Tests\Feature;

use App\Models\AgentTask;
use App\Models\Site;
use App\Models\User;
use App\Support\RunningTasks;
use App\Wire\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunningTaskActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_action_turns_and_waits_while_its_task_runs(): void
    {
        $this->actingAs(User::factory()->create());
        $site = Site::create(['domain' => 'shop.cz', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        $other = Site::create(['domain' => 'blog.cz', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        $action = Action::make('restart')->icon('outline:arrow-path-rounded-square')->busyWhile('site.restart');

        $this->assertFalse($action->isBusy($site));
        $this->assertSame('outline:arrow-path-rounded-square', $action->getIcon($site));

        // A task saved anew is seen at once (the cache of the request is dropped).
        $this->assertFalse(RunningTasks::has('site.restart'));
        AgentTask::create(['op' => 'site.restart', 'status' => AgentTask::RUNNING, 'title' => 'Restart', 'done_title' => 'Hotovo',
            'subject_type' => $site->getMorphClass(), 'subject_id' => $site->id, 'user_id' => auth()->id(), 'started_at' => now()]);

        $this->assertTrue($action->isBusy($site));
        $this->assertTrue($action->isDisabled($site));
        $this->assertSame('outline:arrow-path', $action->getIcon($site));
        $this->assertSame('true', $action->getExtraAttributes($site)['data-busy'] ?? null);
        $this->assertFalse($action->isBusy($other), 'only the row whose task runs');

        AgentTask::sole()->forceFill(['status' => AgentTask::DONE])->save(); // as AgentTasks ends one
        $this->assertFalse($action->isBusy($site));
    }

    public function test_a_pages_job_is_known_by_its_key(): void
    {
        $this->actingAs(User::factory()->create());
        AgentTask::create(['key' => 'system-swap', 'op' => '', 'status' => AgentTask::RUNNING, 'title' => 'Swap', 'done_title' => 'Hotovo',
            'user_id' => auth()->id(), 'started_at' => now()]);

        $this->assertTrue(Action::make('swap')->busyWhile('system-swap')->isBusy());
        $this->assertFalse(Action::make('ntp')->busyWhile('system-ntp')->isBusy());
    }
}
