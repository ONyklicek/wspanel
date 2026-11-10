<?php

namespace Tests\Feature;

use App\Livewire\Databases\ListDatabases;
use App\Livewire\Pages\DatabaseServersPage;
use App\Models\AgentTask;
use App\Models\Database;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Support\AgentTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class DatabaseInstancesTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private MockInterface $agent;

    private Site $site;

    /** @var list<array<string, mixed>> */
    private array $instances = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('sys.versions')->andReturn(['db_engines' => ['mariadb', 'postgresql'], 'db_tools' => ['phpmyadmin', 'adminer']])->byDefault();
        $this->agent->shouldReceive('call')->with('db.server_status')->andReturn(['engines' => []])->byDefault();
        $this->agent->shouldReceive('call')->with('db.instances')->andReturnUsing(fn () => ['instances' => $this->instances])->byDefault();
        $this->agent->shouldReceive('call')->with('db.backups', Mockery::any())->andReturn(['backups' => [], 'schedule' => ['frequency' => 'off']])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
        $this->site = Site::create(['domain' => 'shop.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active']);
    }

    private function instanceRow(array $extra = []): array
    {
        return [...['id' => 1, 'engine' => 'mariadb', 'domain' => 'shop.example.com', 'memory_mb' => 1024, 'cpu_percent' => 100,
            'databases' => [], 'active' => 'active', 'version' => '11.8.3', 'size' => 0, 'host' => 'localhost', 'port' => 3306,
            'socket' => '/run/wspanel-db/1/mysqld.sock'], ...$extra];
    }

    public function test_a_site_gets_its_own_server_in_the_background(): void
    {
        $this->agent->shouldReceive('call')->once()
            ->with('db.instance_create', ['domain' => 'shop.example.com', 'engine' => 'mariadb', 'memory_mb' => 2048, 'cpu_percent' => 200])
            ->andReturn(['job_id' => 'i1']);
        $this->agent->shouldReceive('call')->with('job.status', ['id' => 'i1'])->andReturnUsing(function () {
            $this->instances = [$this->instanceRow(['memory_mb' => 2048, 'cpu_percent' => 200])];

            return ['status' => 'done', 'output' => []];
        });

        $this->pageAction(Livewire::test(DatabaseServersPage::class), 'createInstance', ['site_id' => $this->site->id, 'engine' => 'mariadb', 'memory_mb' => 2048, 'cpu_percent' => 200])
            ->assertHasNoErrors()
            ->assertSee('Vyhrazený MariaDB pro shop.example.com')
            ->call('pollBackgroundJobs')
            ->assertNotified('Vyhrazený MariaDB pro shop.example.com běží')
            ->assertSee('Vyhrazené servery webů')->assertSee('/run/wspanel-db/1/mysqld.sock');
    }

    public function test_limits_are_validated_and_changed(): void
    {
        $this->instances = [$this->instanceRow()];
        $this->agent->shouldNotReceive('call')->with('db.instance_update', Mockery::on(fn ($p) => $p['memory_mb'] < 256));
        $this->agent->shouldReceive('call')->once()->with('db.instance_update', ['id' => 1, 'memory_mb' => 4096, 'cpu_percent' => 0])->andReturn(['job_id' => 'u1']);
        $this->agent->shouldReceive('call')->with('job.status', ['id' => 'u1'])->andReturn(['status' => 'done', 'output' => []]);

        $page = Livewire::test(DatabaseServersPage::class);
        $this->pageAction($page, 'instanceLimits1', ['memory_mb' => 100, 'cpu_percent' => 0])->assertHasErrors();
        $this->pageAction(Livewire::test(DatabaseServersPage::class), 'instanceLimits1', ['memory_mb' => 4096, 'cpu_percent' => 0])
            ->assertHasNoErrors()->assertNotified('Spuštěno na pozadí');
        AgentTasks::settleAll();
        $this->assertSame(['done', 'Limity databázového serveru #1 změněny'], [AgentTask::sole()->status, AgentTask::sole()->done_title]);
    }

    public function test_a_database_goes_into_the_sites_server_and_the_tools_stay_away(): void
    {
        $this->instances = [$this->instanceRow()];
        $this->agent->shouldReceive('call')->once()
            ->with('db.create', ['engine' => 'mariadb', 'name' => 'shop', 'domain' => 'shop.example.com', 'instance' => 1])
            ->andReturn(['name' => 'shop', 'instance' => 1]);

        $list = $this->headerTableAction(Livewire::test(ListDatabases::class), 'createDatabase', ['engine' => 'mariadb', 'site_id' => $this->site->id, 'name' => 'shop', 'dedicated' => true])
            ->assertHasNoErrors();

        $db = Database::sole();
        $this->assertSame(1, $db->instance);
        $this->assertFalse($this->tableActionVisible($list, 'open', $db));
        $this->assertFalse($this->tableActionVisible($list, 'adminer', $db));
        $this->get(route('dbtools.open', [$db, 'adminer']))->assertStatus(409);
    }

    public function test_an_instance_with_databases_is_not_offered_for_deletion(): void
    {
        $this->instances = [$this->instanceRow(['databases' => ['shop']]), $this->instanceRow(['id' => 2, 'engine' => 'postgresql', 'databases' => [], 'socket' => null, 'host' => '/run/wspanel-db/2', 'port' => 6002])];
        $this->agent->shouldReceive('call')->once()->with('db.instance_delete', ['id' => 2, 'confirm' => 'shop.example.com'])->andReturn(['deleted' => true]);
        $this->agent->shouldReceive('call')->once()->with('db.instance_action', ['id' => 1, 'action' => 'restart'])->andReturn([]);
        $this->agent->shouldNotReceive('call')->with('db.instance_action', ['id' => 1, 'action' => 'rm']);

        Livewire::test(DatabaseServersPage::class)
            ->assertSeeHtml('wire:click="deleteInstance(2)"')->assertDontSeeHtml('wire:click="deleteInstance(1)"')
            ->call('controlInstance', 1, 'rm')
            ->call('controlInstance', 1, 'restart')->assertNotified('Server restartován')
            ->call('deleteInstance', 2)->assertNotified('Vyhrazený server smazán');
    }
}
