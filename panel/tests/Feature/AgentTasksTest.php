<?php

namespace Tests\Feature;

use App\Livewire\TaskIndicator;
use App\Models\AgentTask;
use App\Models\Client;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\AgentTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/** A panel operation of several steps, run by the queue worker for its task. */
class FakeStagingSteps
{
    /** @return array{message: string} */
    public function copy(string $domain): array
    {
        return ['message' => 'Staging běží na staging.'.$domain];
    }

    public function broken(): void
    {
        throw new AgentException('op_failed', 'disk full', 'site.pack');
    }
}

class AgentTasksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $agent);
    }

    public function test_a_queued_operation_ends_with_its_own_message_or_its_error(): void
    {
        $this->actingAs(User::factory()->create());

        // The test queue runs it at once; in production the panel-queue worker.
        $ok = AgentTasks::queue('staging.create', ['title' => 'Staging pro a.cz', 'done' => 'Staging hotový'], FakeStagingSteps::class, 'copy', ['a.cz']);
        $this->assertSame(['done', 'Staging běží na staging.a.cz'], [$ok->fresh()->status, $ok->fresh()->done_title]);

        $failed = AgentTasks::queue('staging.create', ['title' => 'Staging pro b.cz', 'done' => 'Staging hotový'], FakeStagingSteps::class, 'broken');
        $this->assertSame(['failed', 'disk full'], [$failed->fresh()->status, $failed->fresh()->error]);
    }

    public function test_the_indicator_tells_how_a_queued_operation_ended_once(): void
    {
        $this->actingAs(User::factory()->create());
        $task = AgentTasks::queue('staging.create', ['title' => 'Staging pro a.cz', 'done' => 'Staging hotový'], FakeStagingSteps::class, 'copy', ['a.cz']);
        $this->assertNull($task->fresh()->notified_at);

        Livewire::test(TaskIndicator::class)->assertDispatched('agent-task-finished')->assertSee('Staging pro a.cz');
        $this->assertNotNull($task->fresh()->notified_at);
        Livewire::test(TaskIndicator::class)->assertNotDispatched('agent-task-finished');
    }

    public function test_a_client_sees_only_its_own_tasks_an_administrator_all(): void
    {
        $client = User::factory()->forClient(Client::create(['name' => 'Alice']))->create();
        $admin = User::factory()->create();
        AgentTask::create(['op' => 'site.backup', 'job_id' => 'j1', 'title' => 'Záloha webu alice.cz', 'done_title' => 'x', 'user_id' => $client->id, 'started_at' => now()]);
        AgentTask::create(['op' => 'php.install', 'job_id' => 'j2', 'title' => 'Instalace PHP 8.5', 'done_title' => 'x', 'user_id' => $admin->id, 'started_at' => now()]);
        $this->app->make(AgentClient::class)->shouldReceive('call')->with('job.status', Mockery::any())->andReturn(['status' => 'running']);

        $this->actingAs($client);
        Livewire::test(TaskIndicator::class)->assertSee('1 úloha běží')->assertSee('Záloha webu alice.cz')->assertDontSee('Instalace PHP 8.5');
        $this->actingAs($admin);
        Livewire::test(TaskIndicator::class)->assertSee('2 úlohy běží')->assertSee('Záloha webu alice.cz');
    }

    public function test_a_job_the_agent_forgot_ends_as_done_with_a_warning(): void
    {
        $this->actingAs(User::factory()->create());
        $this->app->make(AgentClient::class)->shouldReceive('call')->with('job.status', ['id' => 'j9'])
            ->andThrow(new AgentException('not_found', 'no such job', 'job.status'));
        $task = AgentTasks::start(['job_id' => 'j9'], 'sys.upgrade', ['title' => 'Aktualizace systému', 'done' => 'Systém aktualizován']);

        AgentTasks::settleAll();

        $this->assertSame('done', $task->fresh()->status);
        $this->assertTrue($task->fresh()->output['lost']);
    }
}
