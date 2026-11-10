<?php

namespace Tests\Feature;

use App\Enums\DnsProvider;
use App\Livewire\Pages\SystemPage;
use App\Models\DnsZone;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Dns\Resolver;
use App\Services\Notify\Alerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/** The end of the installation: what to set in DNS, checked in public DNS. */
class DnsRecordsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_records_the_server_needs_are_listed_with_their_state(): void
    {
        config(['app.url' => 'https://panel.example.cz', 'panel.public_ipv4' => '85.255.2.143', 'panel.public_ipv6' => '2001:15e8:110:1a8f::1', 'panel.mail_host' => 'mail.example.cz']);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('mail.hosting')->andReturn(['installed' => true]);
        $agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $agent);
        $dns = Mockery::mock(Resolver::class);
        $dns->shouldReceive('records')->with('panel.example.cz', DNS_A, 'ip')->andReturn(['85.255.2.143']);
        $dns->shouldReceive('records')->with('panel.example.cz', DNS_AAAA, 'ipv6')->andReturn([]);
        $dns->shouldReceive('records')->with('mail.example.cz', DNS_A, 'ip')->andReturn(['1.2.3.4']);
        $dns->shouldReceive('records')->with('mail.example.cz', DNS_AAAA, 'ipv6')->andReturn([]);
        $dns->shouldReceive('records')->with('mail.example.cz', DNS_TXT, 'txt')->andReturn([]);
        $dns->shouldReceive('reverse')->with('85.255.2.143')->andReturn('mail.example.cz');
        $this->app->instance(Resolver::class, $dns);

        $this->artisan('panel:dns-records')
            ->expectsOutputToContain('DNS záznamy pro tento server')
            ->expectsOutputToContain('| panel.example.cz | A    | 85.255.2.143')
            ->expectsOutputToContain('✓ nastaveno')
            ->expectsOutputToContain('✗ chybí (teď: 1.2.3.4)')
            ->expectsOutputToContain('v=spf1 a -all')
            ->assertExitCode(1);   // something is missing: a script can tell
    }

    public function test_own_nameservers_must_resolve_here_while_a_zone_lives_on_this_server(): void
    {
        config(['app.url' => 'https://panel.example.cz', 'panel.public_ipv4' => '85.255.2.143', 'panel.public_ipv6' => '']);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('sys.versions')->andReturn(['dns_local' => true]);
        $agent->shouldReceive('call')->with('mail.hosting')->andReturn(['installed' => false]);
        $agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $agent);
        DnsZone::query()->create(['domain' => 'shop.cz', 'provider' => DnsProvider::Local]);
        $dns = Mockery::mock(Resolver::class);
        $dns->shouldReceive('records')->with('panel.example.cz', DNS_A, 'ip')->andReturn(['85.255.2.143']);
        $dns->shouldReceive('records')->with('ns1.panel.example.cz', DNS_A, 'ip')->andReturn(['85.255.2.143']);
        $dns->shouldReceive('records')->with('ns2.panel.example.cz', DNS_A, 'ip')->andReturn([]);
        $dns->shouldReceive('reverse')->andReturn(null);
        $this->app->instance(Resolver::class, $dns);

        $this->artisan('panel:dns-records')
            ->expectsOutputToContain('ns2.panel.example.cz')
            ->expectsOutputToContain('glue')
            ->assertExitCode(1);
    }

    public function test_a_record_that_stops_being_right_is_an_alert_and_shown_in_the_system_page(): void
    {
        config(['app.url' => 'https://panel.example.cz', 'panel.public_ipv4' => '85.255.2.143', 'panel.public_ipv6' => '']);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('mail.hosting')->andReturn(['installed' => false]);
        $agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $agent);
        $dns = Mockery::mock(Resolver::class);
        $dns->shouldReceive('records')->with('panel.example.cz', DNS_A, 'ip')->andReturn(['9.9.9.9'])->once(); // cached afterwards
        $dns->shouldReceive('reverse')->andReturn(null);
        $this->app->instance(Resolver::class, $dns);

        $alert = collect(app(Alerts::class)->current())->firstWhere('key', 'dns:a:panel.example.cz');
        $this->assertNotNull($alert);
        $this->assertStringContainsString('85.255.2.143', $alert['detail']);
        $this->assertStringContainsString('9.9.9.9', $alert['detail']);

        $this->actingAs(User::factory()->create());
        Livewire::test(SystemPage::class)
            ->assertSee(__('DNS serveru'))
            ->assertSee(__('teď: :now', ['now' => '9.9.9.9']));
    }

    public function test_a_server_on_an_internal_name_prints_nothing(): void
    {
        config(['app.url' => 'https://panel.firma.lan', 'panel.public_ipv4' => '10.0.0.5']);
        $this->app->instance(AgentClient::class, Mockery::mock(AgentClient::class)->shouldReceive('call')->andReturn([])->getMock());

        $this->artisan('panel:dns-records')->doesntExpectOutputToContain('DNS záznamy')->assertSuccessful();
    }
}
