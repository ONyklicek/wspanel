<?php

namespace Tests\Feature;

use App\Actions\Dns\ManageDnsZones;
use App\Enums\DnsProvider;
use App\Livewire\Dns\CreateDnsZone;
use App\Livewire\Dns\ListDnsZones;
use App\Livewire\Dns\ViewDnsZone;
use App\Livewire\Dns\ZoneRecords;
use App\Models\DnsZone;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Dns\DnsAdvisor;
use App\Wire\Dns\RecordForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class DnsTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        config(['panel.public_ipv4' => '203.0.113.10', 'panel.public_ipv6' => null, 'app.url' => 'https://panel.example.com']);
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('sys.versions')->andReturn(['dns_local' => true])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    public function test_local_zone_is_created_with_recommended_records(): void
    {
        $this->agent->shouldReceive('call')->once()->with('dns.zone_create', ['domain' => 'example.com', 'nameservers' => ['ns1.panel.example.com', 'ns2.panel.example.com']])->andReturn([]);
        $this->agent->shouldReceive('call')->with('dns.records', ['domain' => 'example.com'])->andReturn(['rrsets' => [
            ['name' => 'example.com', 'type' => 'NS', 'ttl' => 3600, 'records' => ['ns1.panel.example.com.']],
        ]]);
        $set = [];
        $this->agent->shouldReceive('call')->with('dns.record_set', Mockery::on(function ($p) use (&$set) {
            $set[] = $p['name'].' '.$p['type'];

            return true;
        }))->andReturn([]);

        $this->fillForm(Livewire::test(CreateDnsZone::class), ['domain' => 'Example.com', 'provider' => 'local', 'nameservers' => ['ns1.panel.example.com', 'ns2.panel.example.com'], 'recommended' => true])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(DnsProvider::Local, DnsZone::sole()->provider);
        $this->assertSame(['example.com A', 'example.com CAA', 'example.com TXT', '_dmarc.example.com TXT'], $set);
    }

    public function test_recommended_records_never_overwrite_existing_ones(): void
    {
        $zone = DnsZone::create(['domain' => 'example.com', 'provider' => 'local']);
        $this->agent->shouldReceive('call')->with('dns.records', ['domain' => 'example.com'])->andReturn(['rrsets' => [
            ['name' => 'example.com', 'type' => 'A', 'ttl' => 300, 'records' => ['198.51.100.1']],
            ['name' => 'example.com', 'type' => 'TXT', 'ttl' => 300, 'records' => ['"v=spf1 include:_spf.google.com ~all"']],
        ]]);
        $this->agent->shouldReceive('call')->with('dns.record_set', Mockery::on(fn ($p) => ! in_array($p['type'], ['A'], true) && ! ($p['type'] === 'TXT' && $p['name'] === 'example.com')))->andReturn([]);

        $added = app(ManageDnsZones::class)->addRecommended($zone);

        $this->assertSame(['example.com CAA', '_dmarc.example.com TXT'], $added);
    }

    public function test_cloudflare_zone_records_are_grouped_into_rrsets(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones?name=example.com' => Http::response(['success' => true, 'result' => [['id' => 'z1']]]),
            'api.cloudflare.com/client/v4/zones/z1/dns_records?per_page=5000' => Http::response(['success' => true, 'result' => [
                ['id' => 'a', 'type' => 'MX', 'name' => 'example.com', 'content' => 'mx1.example.net', 'priority' => 10, 'ttl' => 1],
                ['id' => 'b', 'type' => 'MX', 'name' => 'example.com', 'content' => 'mx2.example.net', 'priority' => 20, 'ttl' => 1],
            ]]),
        ]);

        $this->fillForm(Livewire::test(CreateDnsZone::class), ['domain' => 'example.com', 'provider' => 'cloudflare', 'cloudflare_token' => 'cf-token', 'recommended' => false])
            ->call('save')
            ->assertHasNoErrors();

        $zone = DnsZone::sole();
        $this->assertSame('z1', $zone->cloudflare_zone_id);
        $this->assertNotSame('cf-token', $zone->getRawOriginal('cloudflare_token'));

        Livewire::test(ZoneRecords::class, ['zone' => $zone->id])->assertSee('10 mx1.example.net')->assertSee('20 mx2.example.net');
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer cf-token'));
    }

    public function test_record_names_are_kept_inside_the_zone(): void
    {
        $this->assertSame('example.com', RecordForm::fqdn('@', 'example.com'));
        $this->assertSame('www.example.com', RecordForm::fqdn('www', 'example.com'));
        $this->assertSame('www.example.com', RecordForm::fqdn('www.example.com.', 'example.com'));
        $this->assertSame(['a', 'b'], RecordForm::values("a\n\n b \r\n"));
    }

    public function test_records_are_added_edited_and_deleted_from_the_zone_page(): void
    {
        $zone = DnsZone::create(['domain' => 'example.com', 'provider' => 'local']);
        $this->agent->shouldReceive('call')->with('dns.records', ['domain' => 'example.com'])->andReturn(['rrsets' => [
            ['name' => 'www.example.com', 'type' => 'A', 'ttl' => 3600, 'records' => ['198.51.100.1']],
            ['name' => 'example.com', 'type' => 'TXT', 'ttl' => 300, 'records' => ['"v=spf1 a mx ~all"']],
        ]]);
        $this->agent->shouldReceive('call')->once()
            ->with('dns.record_set', ['domain' => 'example.com', 'name' => 'mail.example.com', 'type' => 'A', 'ttl' => 600, 'records' => ['203.0.113.10']])
            ->andReturn([]);
        $this->agent->shouldReceive('call')->once()
            ->with('dns.record_set', ['domain' => 'example.com', 'name' => 'www.example.com', 'type' => 'A', 'ttl' => 300, 'records' => ['198.51.100.1', '198.51.100.2']])
            ->andReturn([]);
        $this->agent->shouldReceive('call')->once()
            ->with('dns.record_set', ['domain' => 'example.com', 'name' => 'example.com', 'type' => 'TXT', 'ttl' => 300, 'records' => []])
            ->andReturn([]);

        $this->pageAction(Livewire::test(ViewDnsZone::class, ['record' => $zone->id]), 'add', ['name' => 'mail', 'type' => 'A', 'ttl' => 600, 'records' => "203.0.113.10\n"])
            ->assertHasNoErrors()
            ->assertNotified('Uloženo: mail.example.com A');

        $records = Livewire::test(ZoneRecords::class, ['zone' => $zone->id]);
        $this->tableAction($records, 'www.example.com|A', 'edit', ['ttl' => 300, 'records' => "198.51.100.1\n198.51.100.2"])
            ->assertHasNoErrors()
            ->assertNotified('Uloženo: www.example.com A');
        $this->tableAction($records, 'example.com|TXT', 'delete')
            ->assertNotified('Smazáno: example.com TXT');
    }

    public function test_record_type_must_be_one_of_the_offered(): void
    {
        $zone = DnsZone::create(['domain' => 'example.com', 'provider' => 'local']);
        $this->agent->shouldReceive('call')->with('dns.records', ['domain' => 'example.com'])->andReturn(['rrsets' => []]);
        $this->agent->shouldNotReceive('call')->with('dns.record_set', Mockery::any());

        $this->pageAction(Livewire::test(ViewDnsZone::class, ['record' => $zone->id]), 'add', ['name' => 'x', 'type' => 'PTR', 'ttl' => 600, 'records' => 'a'])
            ->assertHasErrors();
    }

    public function test_secondary_servers_become_the_second_nameserver_of_new_zones(): void
    {
        $this->agent->shouldReceive('call')->once()->with('dns.secondary', ['ips' => ['198.51.100.7'], 'nameservers' => ['ns2.provider.net']])
            ->andReturn(['ips' => ['198.51.100.7'], 'nameservers' => ['ns2.provider.net']]);

        $this->headerTableAction(Livewire::test(ListDnsZones::class), 'secondary', ['ips' => "198.51.100.7\n", 'nameservers' => 'ns2.provider.net'])
            ->assertHasNoErrors()->assertNotified('Sekundární DNS uloženo');
        $this->assertSame(['ns1.panel.example.com', 'ns2.provider.net'], DnsAdvisor::defaultNameservers());

        $this->headerTableAction(Livewire::test(ListDnsZones::class), 'secondary', ['ips' => 'ns.example.com'])->assertHasErrors();
    }

    public function test_dnssec_shows_the_ds_record_and_is_switched_off_only_after_it_is_removed(): void
    {
        $zone = DnsZone::create(['domain' => 'example.com', 'provider' => DnsProvider::Local]);
        $signed = ['domain' => 'example.com', 'enabled' => true, 'ds' => ['37583 13 1 1d6f', '37583 13 2 b0dce355', '37583 13 4 5936']];
        $on = false;
        $this->agent->shouldReceive('call')->with('dns.records', ['domain' => 'example.com'])->andReturn(['rrsets' => []]);
        $this->agent->shouldReceive('call')->with('dns.dnssec', ['domain' => 'example.com', 'action' => 'get'])
            ->andReturnUsing(function () use (&$on, $signed) {
                return $on ? $signed : ['enabled' => false, 'ds' => null];
            });
        $this->agent->shouldReceive('call')->once()->with('dns.dnssec', ['domain' => 'example.com', 'action' => 'enable'])
            ->andReturnUsing(function () use (&$on, $signed) {
                $on = true;

                return $signed;
            });

        $this->pageAction(Livewire::test(ViewDnsZone::class, ['record' => $zone->id]), 'dnssec', ['enabled' => true])
            ->assertHasNoErrors()->assertNotified('37583 13 2 b0dce355');

        $this->agent->shouldNotReceive('call')->with('dns.dnssec', ['domain' => 'example.com', 'action' => 'disable']);
        $this->pageAction(Livewire::test(ViewDnsZone::class, ['record' => $zone->id]), 'dnssec', ['enabled' => false])->assertHasErrors();
        $this->assertSame(['37583 13 2 b0dce355'], ViewDnsZone::preferredDs($signed['ds']));
    }
}
