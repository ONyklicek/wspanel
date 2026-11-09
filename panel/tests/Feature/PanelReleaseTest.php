<?php

namespace Tests\Feature;

use App\Livewire\Pages\UpdatesPage;
use App\Models\Client;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Notify\Alerts;
use App\Support\Attention;
use App\Support\PanelRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PanelReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['panel.version' => '1.2.0', 'panel.release_repo' => 'acme/wspanel']);
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->app->instance(Alerts::class, new class extends Alerts
        {
            public function current(): array
            {
                return [];
            }
        });
    }

    private string $tag = '';

    private MockInterface $agent;

    /** GitHub answers $tag as the newest release (Http::fake keeps its first stub, so it reads a property). */
    private function github(string $tag): void
    {
        if ($this->tag === '') {
            Http::fake([
                'api.github.com/repos/acme/wspanel/releases/latest' => fn () => Http::response(['tag_name' => $this->tag, 'html_url' => "https://github.com/acme/wspanel/releases/tag/{$this->tag}", 'published_at' => '2026-11-01T10:00:00Z']),
                'hooks.slack.com/*' => Http::response('ok'),
            ]);
        }
        $this->tag = $tag;
    }

    public function test_a_newer_release_is_found_and_shown_to_the_administrator(): void
    {
        $this->github('v1.3.0');
        PanelRelease::check();

        $this->assertSame('1.3.0', PanelRelease::available()['version']);
        $this->actingAs(User::factory()->create());
        $item = collect(Attention::items())->firstWhere('level', 'info');
        $this->assertStringContainsString('WSPanel 1.3.0', $item['title']);
        $this->assertStringContainsString('curl -fsSL https://github.com/acme/wspanel/releases/latest/download/get.sh | sudo bash', $item['detail']);

        Livewire::test(UpdatesPage::class)->assertSee('Aktualizovat na 1.3.0')->assertSee('releases/latest/download/get.sh');
    }

    public function test_a_server_installed_through_apt_is_told_the_apt_command(): void
    {
        config(['panel.install_method' => 'apt']);
        $this->github('v1.3.0');
        PanelRelease::check();
        $this->actingAs(User::factory()->create());

        $this->assertStringContainsString('sudo wspanel-update', collect(Attention::items())->firstWhere('level', 'info')['detail']);
    }

    public function test_the_same_or_an_older_release_is_no_news(): void
    {
        foreach (['v1.2.0', 'v1.1.9', 'v1.2.0-rc1'] as $tag) {
            $this->github($tag);
            PanelRelease::check();
            $this->assertNull(PanelRelease::available(), $tag);
        }
    }

    public function test_a_client_is_not_told_about_server_upgrades(): void
    {
        $this->github('v1.3.0');
        PanelRelease::check();
        $this->actingAs(User::factory()->forClient(Client::factory()->create())->create());

        $this->assertNull(collect(Attention::items())->firstWhere('level', 'info'));
    }

    public function test_the_administrators_are_notified_once_per_version(): void
    {
        $admin = User::factory()->create();
        NotificationChannel::withoutGlobalScopes()->create(['user_id' => $admin->id, 'type' => 'slack', 'name' => 'tým',
            'config' => ['url' => 'https://hooks.slack.com/services/T000/B000/xxxxxxxxxx'], 'events' => ['problems']]);
        $posted = fn () => Http::recorded()->filter(fn ($r) => str_contains($r[0]->url(), 'hooks.slack.com'))->map(fn ($r) => strtok($r[0]['text'], "\n"))->values()->all();

        $this->github('v1.3.0');
        PanelRelease::check();
        $this->artisan('panel:alerts');
        $this->artisan('panel:alerts');
        $this->assertSame(['Nová verze WSPanel 1.3.0'], $posted());

        $this->github('v1.4.0');
        PanelRelease::check();
        $this->artisan('panel:alerts');
        $this->assertSame(['Nová verze WSPanel 1.3.0', 'Nová verze WSPanel 1.4.0'], $posted());
    }

    public function test_a_build_from_source_asks_nobody(): void
    {
        config(['panel.version' => null]);
        Http::fake();

        PanelRelease::check();

        Http::assertNothingSent();
        $this->assertNull(PanelRelease::available());
    }

    public function test_a_failed_check_is_kept_and_shown(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $out = PanelRelease::check();

        $this->assertStringContainsString('acme/wspanel', $out['error']);
        $this->actingAs(User::factory()->create());
        Livewire::test(UpdatesPage::class)->assertSee('není žádné vydání');
    }

    public function test_the_upgrade_runs_from_the_button_and_is_followed(): void
    {
        $this->github('v1.3.0');
        PanelRelease::check();
        $this->actingAs(User::factory()->create());
        $this->agent->shouldReceive('call')->once()->with('panel.upgrade', ['version' => '1.3.0'])->andReturn(['started' => true]);

        Livewire::test(UpdatesPage::class)->assertSee('Aktualizovat na 1.3.0')->assertDontSee('Zkontrolovat aktualizace')
            ->call('upgradePanel')->assertNotified();

        $this->agent->shouldReceive('call')->with('panel.upgrade_status')->andReturn(['state' => 'running', 'version' => '1.3.0', 'log' => ['==> Panel']]);
        Livewire::test(UpdatesPage::class)->assertSee('Probíhá aktualizace na 1.3.0')->assertDontSee('Aktualizovat na 1.3.0');
        $this->getJson('/updates/upgrade-status')->assertOk()->assertJson(['state' => 'running', 'version' => '1.3.0', 'current' => '1.2.0']);
    }

    public function test_a_failed_upgrade_is_shown_until_the_server_has_that_version(): void
    {
        $this->actingAs(User::factory()->create());
        $this->agent->shouldReceive('call')->with('panel.upgrade_status')->andReturn(
            ['state' => 'failed', 'version' => '1.3.0', 'log' => ['==> get.sh', '#=#=#', '  12.5%', 'checksum mismatch']],
            ['state' => 'failed', 'version' => '1.1.0', 'log' => []],
        );

        Livewire::test(UpdatesPage::class)->assertSee('Aktualizace na 1.3.0 se nepodařila')->assertSee('checksum mismatch')->assertDontSee('12.5%');
        Livewire::test(UpdatesPage::class)->assertDontSee('se nepodařila'); // 1.2.0 runs now
    }

    public function test_an_up_to_date_server_offers_a_check_and_no_upgrade(): void
    {
        $this->github('v1.2.0');
        PanelRelease::check();
        $this->actingAs(User::factory()->create());
        $this->agent->shouldNotReceive('call')->with('panel.upgrade', Mockery::any());

        Livewire::test(UpdatesPage::class)->assertSee('aktuální')->assertSee('Zkontrolovat aktualizace')->assertDontSee('Aktualizovat na')
            ->call('upgradePanel');
    }

    public function test_a_release_candidate_follows_newer_candidates(): void
    {
        config(['panel.version' => '1.2.0-rc9']);
        Http::fake(['api.github.com/repos/acme/wspanel/releases*' => Http::response([
            ['tag_name' => 'v1.2.0-rc10', 'html_url' => 'https://github.com/acme/wspanel/releases/tag/v1.2.0-rc10', 'draft' => false],
            ['tag_name' => 'v1.3.0-rc1', 'html_url' => 'https://github.com/acme/wspanel/releases/tag/v1.3.0-rc1', 'draft' => true],
            ['tag_name' => 'v1.2.0-rc9', 'html_url' => 'https://github.com/acme/wspanel/releases/tag/v1.2.0-rc9', 'draft' => false],
        ])]);

        PanelRelease::check();

        $this->assertSame('1.2.0-rc10', PanelRelease::available()['version']);
    }

    public function test_only_an_administrator_reads_the_upgrade_state(): void
    {
        $this->actingAs(User::factory()->forClient(Client::factory()->create())->create());
        $this->getJson('/updates/upgrade-status')->assertForbidden();
    }
}
