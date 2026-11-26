<?php

namespace Tests\Feature;

use App\Actions\System\ManagePublicAddress;
use App\Livewire\Pages\SystemPage;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Dns\Resolver;
use App\Services\Dns\ServerDns;
use App\Services\Notify\Alerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/** The server's own records in public DNS, watched after the installation too. */
class ServerDnsTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $dns;

    /** @var array<string, list<string>> name|type => answer */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://panel.example.cz', 'panel.public_ipv4' => '85.255.2.143', 'panel.public_ipv6' => '']);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('sys.versions')->andReturn(['dns_local' => true, 'panel_units' => []]);
        $agent->shouldReceive('call')->with('mail.hosting')->andReturn(['installed' => false]);
        $agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $agent);
        $this->dns = Mockery::mock(Resolver::class);
        $this->dns->shouldReceive('records')->andReturnUsing(fn (string $name, int $type) => $this->answers[$name.'|'.$type] ?? []);
        $this->dns->shouldReceive('reverse')->andReturn(null);
        $this->app->instance(Resolver::class, $this->dns);
        $this->answers['panel.example.cz|'.DNS_A] = ['85.255.2.143'];
    }

    public function test_a_recheck_after_the_record_is_fixed_clears_the_alert(): void
    {
        $this->answers['panel.example.cz|'.DNS_A] = ['1.2.3.4'];

        $alerts = collect(app(Alerts::class)->current())->keyBy('key');
        $this->assertSame('warning', $alerts['dns:a:panel.example.cz']['level']);
        $this->assertStringContainsString('Má být 85.255.2.143, teď 1.2.3.4.', $alerts['dns:a:panel.example.cz']['detail']);

        $this->answers['panel.example.cz|'.DNS_A] = ['85.255.2.143'];
        $this->actingAs(User::factory()->create());
        Livewire::test(SystemPage::class)
            ->assertSee('panel.example.cz')
            ->call('refreshDns')
            ->assertSet('dns.0.ok', true);
        $this->assertArrayNotHasKey('dns:a:panel.example.cz', collect(app(Alerts::class)->current())->keyBy('key')->all());
    }

    public function test_a_server_without_a_public_address_checks_nothing(): void
    {
        config(['panel.public_ipv4' => '10.0.0.5']);

        $this->assertSame([], app(ServerDns::class)->checks());
    }

    public function test_behind_nat_the_address_the_internet_sees_is_asked_for(): void
    {
        config(['panel.public_ipv4' => '192.168.1.20']);
        Cache::forget(ManagePublicAddress::DETECTED);
        Http::fake([ManagePublicAddress::TRACE_URL => Http::response("fl=1\nip=85.255.2.143\nts=1\n")]);

        $rows = app(ServerDns::class)->checks();

        $this->assertSame(['ip:v4'], array_column($rows, 'key'));
        $this->assertSame('85.255.2.143', $rows[0]['want']);
        $alerts = collect(app(Alerts::class)->current())->keyBy('key');
        $this->assertStringContainsString('Má být 85.255.2.143, teď 192.168.1.20.', $alerts['dns:ip:v4']['detail']);
    }

    public function test_the_address_the_internet_sees_is_taken_from_the_system_page(): void
    {
        config(['panel.public_ipv4' => '192.168.1.20']);
        Cache::put(ManagePublicAddress::DETECTED, ['ipv4' => '85.255.2.143', 'ipv6' => ''], 600);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('panel.public_address', ['ipv4' => '85.255.2.143', 'ipv6' => ''])->once()->andReturn([]);
        $agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $agent);
        $this->actingAs(User::factory()->create());

        Livewire::test(SystemPage::class)
            ->assertSee('Použít 85.255.2.143')
            ->call('useSeenAddress')
            ->assertSet('address.ipv4', '85.255.2.143')
            ->assertSet('internal', false)
            ->assertDontSee('Použít 85.255.2.143');
    }

    public function test_an_internal_dns_answering_with_the_lan_address_points_here(): void
    {
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('sys.versions')->andReturn(['dns_local' => false, 'panel_units' => []]);
        $agent->shouldReceive('call')->with('sys.network')->andReturn(['interfaces' => [['name' => 'eth0', 'addresses' => ['192.168.1.20/24', 'fe80::1/64']]]]);
        $agent->shouldReceive('call')->with('mirror.status')->andReturn(['role' => 'primary', 'lan_address' => '192.168.1.20', 'peer' => ['address' => '192.168.1.21']]);
        $agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $agent);

        // Windows DNS (split horizon) answers with the active node's LAN address.
        $this->answers['panel.example.cz|'.DNS_A] = ['192.168.1.21'];
        $this->assertTrue(app(ServerDns::class)->checks()[0]['ok']);

        // Somewhere else entirely: still wrong.
        $this->answers['panel.example.cz|'.DNS_A] = ['192.168.7.7'];
        $this->assertFalse(app(ServerDns::class)->checks()[0]['ok']);
    }
}
