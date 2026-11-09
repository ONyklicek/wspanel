<?php

namespace Tests\Feature;

use App\Models\CronJob;
use App\Models\NotificationChannel;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Notify\Alerts;
use App\Services\Notify\Heartbeat;
use App\Services\Notify\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class HealthAlertsTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $agent;

    /** @var array<string, mixed> */
    private array $metrics = ['cpus' => 4, 'load' => [0.5, 0.5, 0.5], 'memory' => ['used' => 2, 'total' => 8], 'filesystems' => []];

    /** @var list<array<string, mixed>> */
    private array $queue = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('sys.metrics')->andReturnUsing(fn () => $this->metrics);
        $this->agent->shouldReceive('call')->with('mail.queue', ['action' => 'list'])->andReturnUsing(fn () => ['messages' => $this->queue]);
        $this->agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
    }

    /** @return array<string, string> key => level */
    private function alerts(): array
    {
        return collect(app(Alerts::class)->current())->mapWithKeys(fn (array $a) => [$a['key'] => $a['level']])->all();
    }

    public function test_a_quiet_server_has_nothing_to_report(): void
    {
        $this->assertSame([], $this->alerts());
    }

    public function test_memory_load_and_stuck_mail_are_reported(): void
    {
        $this->metrics['memory'] = ['used' => 79, 'total' => 80];
        $this->metrics['load'] = [9.5, 8, 7];
        $this->queue = array_fill(0, Alerts::STUCK_MAIL_COUNT, ['arrival' => now()->subHours(2)->getTimestamp()]);
        $this->queue[] = ['arrival' => now()->getTimestamp()]; // a fresh one is fine

        $this->assertSame(['memory' => 'warning', 'load' => 'warning', 'mail-queue' => 'warning'], $this->alerts());
    }

    public function test_a_failed_site_and_a_failed_scheduled_task_are_reported(): void
    {
        $site = Site::acrossClients()->create(['domain' => 'a.cz', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'failed', 'last_error' => 'boom']);
        CronJob::create(['site_id' => $site->id, 'agent_id' => 1, 'kind' => 'php', 'args' => ['backup.php'], 'schedule' => '0 3 * * *', 'last_result' => 'exit-code']);
        CronJob::create(['site_id' => $site->id, 'agent_id' => 2, 'kind' => 'php', 'args' => ['ok.php'], 'schedule' => '0 4 * * *', 'last_result' => 'success']);

        $alerts = $this->alerts();
        $this->assertSame('danger', $alerts['site-failed:a.cz'] ?? null);
        $this->assertCount(1, array_filter(array_keys($alerts), fn ($k) => str_starts_with($k, 'cron:')));
    }

    public function test_ntfy_goes_to_ntfy_sh_with_a_title(): void
    {
        Http::fake(['ntfy.sh/*' => Http::response('{}')]);
        $user = User::factory()->create();
        $channel = NotificationChannel::withoutGlobalScopes()->create(['user_id' => $user->id, 'type' => 'ntfy', 'name' => 'mobil',
            'config' => ['topic' => 'wspanel-abcdefgh12345'], 'events' => ['problems']]);

        $this->assertTrue(app(Notifier::class)->send($channel, 'Problém: a.cz neodpovídá', 'HTTP 502'));

        Http::assertSent(fn ($r) => $r->url() === 'https://ntfy.sh/wspanel-abcdefgh12345'
            && $r->hasHeader('Title', 'WSPanel: Problém: a.cz neodpovídá') && $r->body() === 'HTTP 502');
    }

    public function test_the_heartbeat_pings_or_reports_the_serious_problems(): void
    {
        Http::fake(['hc-ping.com/*' => Http::response('OK')]);
        $url = 'https://hc-ping.com/0f9c1f6e-2b1d-4c3a-9f4e-1a2b3c4d5e6f';

        $this->artisan('panel:heartbeat')->expectsOutputToContain('no heartbeat check set');
        Http::assertNothingSent();

        Heartbeat::configure($url);
        $this->artisan('panel:heartbeat')->expectsOutputToContain('pinged, 0 problems');
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === $url);

        Site::acrossClients()->create(['domain' => 'a.cz', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'failed', 'last_error' => 'boom']);
        $this->artisan('panel:heartbeat')->expectsOutputToContain('pinged, 1 problems');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === $url.'/fail' && str_contains($r->body(), 'a.cz'));
        $this->assertTrue(Setting::get('heartbeat.last')['ok']);
    }

    public function test_the_heartbeat_takes_only_healthchecks_addresses(): void
    {
        Heartbeat::configure('http://169.254.169.254/latest');

        $this->assertNull(Heartbeat::url());
    }
}
