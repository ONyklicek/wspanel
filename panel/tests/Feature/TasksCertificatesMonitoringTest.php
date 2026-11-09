<?php

namespace Tests\Feature;

use App\Livewire\Network\ListCertificates;
use App\Livewire\Sites\ListCronJobs;
use App\Models\Certificate;
use App\Models\CronJob;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class TasksCertificatesMonitoringTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('sys.versions')->andReturn(['frankenphp' => 'FrankenPHP v1.13.5 PHP 8.5.12 Caddy v2.11.9'])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    private function laravelSite(): Site
    {
        return Site::create(['domain' => 'app.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active']);
    }

    public function test_creating_a_task_sends_plain_args_to_the_agent(): void
    {
        $site = $this->laravelSite();
        $this->agent->shouldReceive('call')->once()
            ->with('cron.add', ['domain' => 'app.example.com', 'kind' => 'artisan', 'args' => ['backup:run', '--only-db'], 'schedule' => '0 3 * * *'])
            ->andReturn(['id' => 1]);
        $this->agent->shouldReceive('call')->once()->with('cron.list', ['domain' => 'app.example.com'])
            ->andReturn([['id' => 1, 'kind' => 'artisan', 'args' => ['backup:run', '--only-db'], 'schedule' => '0 3 * * *', 'calendar' => '*-*-* 03:00:00']]);

        $this->headerTableAction(Livewire::test(ListCronJobs::class), 'create', ['site_id' => $site->id, 'kind' => 'artisan', 'command' => 'php artisan backup:run   --only-db', 'schedule' => ' 0 3  * * * '])
            ->assertHasNoErrors();

        $job = CronJob::sole();
        $this->assertSame('php artisan backup:run --only-db', $job->commandLine());
    }

    public function test_shell_characters_and_bad_schedules_never_reach_the_agent(): void
    {
        $site = $this->laravelSite();
        $this->agent->shouldNotReceive('call')->with('cron.add', Mockery::any());

        $this->headerTableAction(Livewire::test(ListCronJobs::class), 'create', ['site_id' => $site->id, 'kind' => 'artisan', 'command' => 'x; rm -rf ~', 'schedule' => '* * *'])
            ->assertHasErrors();
    }

    public function test_symfony_sites_offer_console_not_artisan(): void
    {
        $site = Site::create(['domain' => 'sf.example.com', 'mode' => 'classic', 'framework' => 'symfony', 'php_version' => '8.4', 'status' => 'active']);

        $this->headerTableAction(Livewire::test(ListCronJobs::class), 'create', ['site_id' => $site->id, 'kind' => 'artisan', 'command' => 'inspire', 'schedule' => '* * * * *'])
            ->assertHasErrors();
    }

    public function test_certificate_precheck_reports_what_is_missing(): void
    {
        $cert = Certificate::create(['domain' => 'app.example.com', 'status' => 'missing']);
        $this->agent->shouldReceive('call')->once()->with('ssl.check', ['domain' => 'app.example.com'])->andReturn([
            'resolves_to' => ['203.0.113.9'], 'server_addresses' => ['198.51.100.4'],
            'dns_points_here' => false, 'port_443_open' => true, 'note' => 'Behind NAT…',
        ]);

        $this->tableAction(Livewire::test(ListCertificates::class), $cert, 'check')
            ->assertNotified('app.example.com: něco chybí');
    }
}
