<?php

namespace Tests\Feature;

use App\Livewire\Pages\PackagesPage;
use App\Livewire\Pages\ProcessesPage;
use App\Livewire\Pages\SystemPage;
use App\Livewire\Pages\UpdatesPage;
use App\Models\Client;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class SystemManagementTest extends TestCase
{
    use RefreshDatabase, RunsWireActions;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    private function settings(array $over = []): array
    {
        return $over + [
            'hostname' => 'web1.example.com', 'timezone' => 'Europe/Prague', 'ntp' => true, 'ntp_available' => true,
            'ntp_synchronized' => true, 'time' => '2026-11-09T15:00:00+01:00', 'swaps' => [], 'swappiness' => 60, 'swap_file' => '/swapfile',
        ];
    }

    public function test_the_system_pages_are_for_administrators_only(): void
    {
        $this->actingAs(User::factory()->forClient(Client::factory()->create())->create());
        foreach (['/system', '/processes', '/system-packages'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_a_restart_is_scheduled_for_the_night_and_cancelled(): void
    {
        $this->agent->shouldReceive('call')->with('sys.updates', [])->andReturn(['packages' => [], 'reboot_required' => true, 'auto_security' => true, 'auto_reboot' => false, 'auto_reboot_time' => '', 'reboot_scheduled' => '']);
        $this->agent->shouldReceive('call')->once()->with('sys.reboot', ['at' => '03:30'])->andReturn(['scheduled' => '2026-11-10T02:30:00Z']);
        $this->agent->shouldReceive('call')->once()->with('sys.reboot', ['cancel' => true])->andReturn([]);
        $this->agent->shouldReceive('call')->once()->with('sys.auto_reboot', ['enabled' => true, 'time' => '04:00'])->andReturn([]);

        $page = Livewire::test(UpdatesPage::class);
        $this->pageAction($page, 'reboot', ['when' => 'at', 'at' => '25:00'])->assertHasErrors();
        $this->pageAction(Livewire::test(UpdatesPage::class), 'reboot', ['when' => 'at', 'at' => '03:30'])->assertHasNoErrors();
        $this->pageAction(Livewire::test(UpdatesPage::class), 'autoReboot', ['enabled' => true, 'time' => '04:00'])->assertHasNoErrors();
        Livewire::test(UpdatesPage::class)->call('cancelReboot')->assertNotified();
    }

    public function test_the_system_page_renames_the_server_and_sets_swap(): void
    {
        $this->agent->shouldReceive('call')->with('sys.settings', [])->andReturn($this->settings(['swap_file_size' => 1073741824, 'swappiness' => 60]));
        $this->agent->shouldReceive('call')->with('sys.network')->andReturn(['interfaces' => [['name' => 'eth0', 'state' => 'up', 'mac' => 'aa:bb', 'mtu' => 1500, 'addresses' => ['203.0.113.5/24']]], 'gateways' => [['gateway' => '203.0.113.1', 'dev' => 'eth0']], 'resolvers' => ['1.1.1.1']]);
        $this->agent->shouldReceive('call')->with('sys.disks')->andReturn(['installed' => true, 'disks' => [['name' => '/dev/sda', 'type' => 'sat', 'model' => 'WDC', 'serial' => 'S', 'capacity' => 1000, 'available' => true, 'passed' => true, 'temperature' => 40, 'power_on_hours' => 480, 'reallocated' => 8, 'pending' => 0, 'wear_percent' => null]]]);
        $this->agent->shouldReceive('call')->once()->with('sys.hostname', ['hostname' => 'web2.example.com'])->andReturn([]);
        // The size did not change: only swappiness goes to the agent.
        $this->agent->shouldReceive('call')->once()->with('sys.swap', ['swappiness' => 10])->andReturn([]);

        $page = Livewire::test(SystemPage::class)->assertSee('web1.example.com')->assertSee('203.0.113.5/24')->assertSee('pozor')->assertSee('20 dní');
        $this->pageAction($page, 'hostname', ['hostname' => 'Web 2'])->assertHasErrors();
        $this->pageAction(Livewire::test(SystemPage::class), 'hostname', ['hostname' => 'web2.example.com'])->assertHasNoErrors();
        $this->pageAction(Livewire::test(SystemPage::class), 'swap', ['size_mb' => 100, 'swappiness' => 10])->assertHasErrors();
        $this->pageAction(Livewire::test(SystemPage::class), 'swap', ['size_mb' => 1024, 'swappiness' => 10])->assertHasNoErrors();
    }

    public function test_a_process_is_ended_by_its_pid_and_start(): void
    {
        $this->agent->shouldReceive('call')->with('sys.processes')->andReturn(['processes' => [
            ['pid' => 812, 'ppid' => 1, 'user' => 'dj_example', 'name' => 'gunicorn', 'cmd' => 'gunicorn app', 'state' => 'S', 'cpu' => 2.5, 'rss' => 50_000_000, 'start' => 9001, 'started' => '2026-11-09T10:00:00Z', 'site' => 'dj.example.com'],
            ['pid' => 900, 'ppid' => 1, 'user' => 'root', 'name' => 'cron', 'cmd' => '/usr/sbin/cron -f', 'state' => 'S', 'cpu' => 0, 'rss' => 1_000_000, 'start' => 50, 'started' => '2026-11-09T09:00:00Z'],
        ]]);
        $this->agent->shouldReceive('call')->once()->with('sys.kill', ['pid' => 812, 'start' => 9001, 'signal' => 'KILL'])->andReturn([]);

        Livewire::test(ProcessesPage::class)
            ->assertSee('gunicorn app')->assertSee('/usr/sbin/cron -f')
            ->set('search', 'dj.example')->assertSee('gunicorn app')->assertDontSee('/usr/sbin/cron -f')
            ->call('terminate', 812, 9001, true)->assertNotified();
    }

    public function test_disk_usage_drills_into_a_directory(): void
    {
        $this->agent->shouldReceive('call')->with('sys.disk_usage', ['path' => '/var'])->andReturn(['path' => '/var', 'total' => 3000, 'entries' => [['path' => '/var/log', 'size' => 2000]], 'partial' => false]);
        $this->agent->shouldReceive('call')->with('sys.disk_usage', ['path' => '/var/log'])->andReturn(['path' => '/var/log', 'total' => 2000, 'entries' => [], 'partial' => true]);

        Livewire::test(ProcessesPage::class)
            ->set('path', '/var')->call('browse')->assertSee('/var/log')
            ->call('browse', '/var/log')->assertSee('některé složky nešly přečíst')
            ->call('up')->assertSet('path', '/var');
    }

    public function test_a_package_is_installed_only_after_the_preview(): void
    {
        $this->agent->shouldReceive('call')->with('pkg.list')->andReturn(['packages' => [
            ['name' => 'htop', 'version' => '3.4', 'summary' => 'viewer', 'size' => 1, 'manual' => true, 'held' => false, 'protected' => false],
            ['name' => 'bash', 'version' => '5.2', 'summary' => 'shell', 'size' => 1, 'manual' => true, 'held' => false, 'protected' => true],
            ['name' => 'libfoo', 'version' => '1', 'summary' => 'dependency', 'size' => 1, 'manual' => false, 'held' => false, 'protected' => false],
        ]]);
        $this->agent->shouldReceive('call')->with('pkg.search', ['query' => 'ncdu'])->andReturn(['packages' => [['name' => 'ncdu', 'version' => '', 'summary' => 'disk usage', 'protected' => false]], 'total' => 1]);
        $this->agent->shouldReceive('call')->once()->with('pkg.preview', ['name' => 'ncdu', 'action' => 'install'])->andReturn(['install' => ['ncdu'], 'remove' => [], 'allowed' => true]);
        $this->agent->shouldReceive('call')->once()->with('pkg.install', ['name' => 'ncdu'])->andReturn(['installed' => true]);

        Livewire::test(PackagesPage::class)
            ->assertSee('htop')->assertDontSee('libfoo')
            ->set('query', 'ncdu; rm')->call('findPackages')->assertHasErrors('query')
            ->set('query', 'ncdu')->call('findPackages')->assertSee('disk usage')
            ->call('ask', 'install', 'ncdu')->assertSee('Nainstaluje se:')
            ->call('confirmPending')->assertSet('pending', null)->assertNotified();
    }

    public function test_installed_packages_are_searched_without_riding_the_component_state(): void
    {
        $this->agent->shouldReceive('call')->once()->with('pkg.list')->andReturn(['packages' => [
            ['name' => 'htop', 'version' => '3.4', 'summary' => 'process viewer', 'size' => 1, 'manual' => true, 'held' => false, 'protected' => false],
            ['name' => 'libfoo', 'version' => '1', 'summary' => 'dependency', 'size' => 1, 'manual' => false, 'held' => false, 'protected' => false],
        ]]);

        $page = Livewire::test(PackagesPage::class)->assertSet('installedTotal', 2);
        $this->assertStringNotContainsString('process viewer', json_encode($page->snapshot['data']));

        $page->set('filter', 'LIBF')->assertDontSee('libfoo') // only manual ones by default…
            ->assertSee('Hledat ve všech')
            ->set('manualOnly', false)->assertSee('libfoo')->assertDontSee('process viewer')
            ->set('filter', 'viewer')->assertSee('process viewer')->assertDontSee('dependency');    // the summary is searched too; pkg.list asked once
    }

    public function test_a_removal_the_panel_needs_is_not_offered(): void
    {
        $this->agent->shouldReceive('call')->once()->with('pkg.preview', ['name' => 'libfoo', 'action' => 'remove'])->andReturn(['install' => [], 'remove' => ['libfoo', 'postfix'], 'allowed' => false, 'reason' => 'name: the server or the panel needs postfix (it would be removed too)']);
        $this->agent->shouldNotReceive('call')->with('pkg.remove', Mockery::any());

        Livewire::test(PackagesPage::class)
            ->call('ask', 'remove', 'libfoo')->assertSee('the server or the panel needs postfix')
            ->call('confirmPending');
    }
}
