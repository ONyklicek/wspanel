<?php

namespace Tests\Feature;

use App\Actions\Dns\ManageSiteDns;
use App\Actions\Sites\ManageEdge;
use App\Enums\DnsProvider;
use App\Livewire\Sites\CreateSitePage;
use App\Livewire\Sites\SiteDns;
use App\Models\DnsZone;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Dns\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class SiteDnsTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private MockInterface $agent;

    /** @var array<string, list<array<string, mixed>>> zone => rrsets */
    private array $zones = [];

    /** @var list<string> "name TYPE" written */
    private array $set = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['panel.public_ipv4' => '203.0.113.10', 'panel.public_ipv6' => null, 'app.url' => 'https://panel.example.com']);
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->andReturn([])->byDefault(); // first: the later defaults win over it
        $this->agent->shouldReceive('call')->with('sys.versions')->andReturn(['dns_local' => true, 'php' => [['version' => '8.3', 'installed' => true], ['version' => '8.4', 'installed' => true]], 'php_default' => '8.4', 'db_engines' => []])->byDefault();
        $this->agent->shouldReceive('call')->with('mail.dkim', Mockery::any())->andReturn([])->byDefault();
        $this->agent->shouldReceive('call')->with('mail.hosting')->andReturn(['installed' => false])->byDefault();
        $this->agent->shouldReceive('call')->with('dns.records', Mockery::any())->andReturnUsing(fn ($op, $p) => ['rrsets' => $this->zones[$p['domain']] ?? []])->byDefault();
        $this->agent->shouldReceive('call')->with('dns.record_set', Mockery::any())->andReturnUsing(function ($op, $p) {
            $this->set[] = $p['name'].' '.$p['type'];
            $this->zones[$p['domain']][] = ['name' => $p['name'], 'type' => $p['type'], 'ttl' => $p['ttl'], 'records' => $p['records']];

            return [];
        })->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        // The world sees shop.example.com here, blog.example.com elsewhere.
        $this->app->instance(Resolver::class, new class extends Resolver
        {
            public function records(string $name, int $type, string $field): array
            {
                return $type === DNS_A ? (['shop.example.com' => ['203.0.113.10'], 'blog.example.com' => ['198.51.100.1']][$name] ?? []) : [];
            }
        });
        $this->actingAs(User::factory()->create());
    }

    private function zone(string $domain = 'example.com'): DnsZone
    {
        $this->zones[$domain] = [['name' => $domain, 'type' => 'NS', 'ttl' => 3600, 'records' => ['ns1.panel.example.com.']]];

        return DnsZone::create(['domain' => $domain, 'provider' => DnsProvider::Local]);
    }

    public function test_the_closest_zone_above_a_name_is_its_zone(): void
    {
        $this->zone('example.com');
        $this->zone('shop.example.com');

        $this->assertSame('shop.example.com', ManageSiteDns::zoneFor('www.shop.example.com')?->domain);
        $this->assertSame('example.com', ManageSiteDns::zoneFor('blog.example.com')?->domain);
        $this->assertNull(ManageSiteDns::zoneFor('example.org'));
        $this->assertNull(ManageSiteDns::zoneFor('notexample.com'));
    }

    public function test_a_new_site_goes_into_the_zone_that_covers_it(): void
    {
        $this->zone();
        $this->agent->shouldReceive('call')->once()->with('site.create', Mockery::any())->andReturn([
            'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'system_user' => 'web_shop_example_com',
            'home' => '/srv/sites/shop.example.com', 'doc_root' => '/srv/sites/shop.example.com/current/public',
        ]);

        $page = $this->fillForm(Livewire::test(CreateSitePage::class), ['domain' => 'shop.example.com', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4']);
        $this->assertSame('zone', $page->get('data.dns')); // preselected: the panel has example.com
        $page->call('save')->assertHasNoErrors()->assertNotified('DNS v zóně example.com');

        $this->assertContains('shop.example.com A', $this->set);
        $this->assertContains('_dmarc.shop.example.com TXT', $this->set);
    }

    public function test_the_tab_shows_what_is_missing_and_fills_it_without_overwriting(): void
    {
        $this->zone();
        $this->zones['example.com'][] = ['name' => 'blog.example.com', 'type' => 'A', 'ttl' => 3600, 'records' => ['198.51.100.1']];
        $site = Site::create(['domain' => 'shop.example.com', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active',
            'edge_settings' => ['aliases' => ['blog.example.com']]]);

        $status = app(ManageSiteDns::class)->status($site);
        $byName = collect($status['records'])->keyBy(fn ($r) => $r['name'].' '.$r['type']);
        $this->assertSame('missing', $byName['shop.example.com A']['in_zone']);
        $this->assertTrue($byName['shop.example.com A']['public']);
        $this->assertSame('differs', $byName['blog.example.com A']['in_zone']);
        $this->assertFalse($byName['blog.example.com A']['public']);

        Livewire::test(SiteDns::class, ['record' => $site->id])
            ->assertSee('Doplnit chybějící')->assertSee('jiná hodnota')
            ->call('fillDns')->assertNotified();

        $this->assertContains('shop.example.com A', $this->set);
        $this->assertNotContains('blog.example.com A', $this->set); // pointed elsewhere: left alone
    }

    public function test_a_long_txt_split_in_strings_is_the_same_value(): void
    {
        $this->zone();
        $site = Site::create(['domain' => 'shop.example.com', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        $this->agent->shouldReceive('call')->with('mail.dkim', Mockery::any())->andReturn(['name' => 'wspanel._domainkey.shop.example.com', 'value' => 'v=DKIM1; k=rsa; p=ABCDEF']);
        $this->zones['example.com'][] = ['name' => 'wspanel._domainkey.shop.example.com', 'type' => 'TXT', 'ttl' => 3600, 'records' => ['"v=DKIM1; k=rsa; p=ABC" "DEF"']];

        $dkim = collect(app(ManageSiteDns::class)->status($site)['records'])->first(fn ($r) => str_starts_with($r['name'], 'wspanel._domainkey'));
        $this->assertSame('ok', $dkim['in_zone']);
    }

    public function test_a_site_without_a_zone_gets_one_from_its_tab(): void
    {
        $site = Site::create(['domain' => 'www.firma.cz', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        $this->agent->shouldReceive('call')->once()->with('dns.zone_create', ['domain' => 'firma.cz', 'nameservers' => ['ns1.panel.example.com', 'ns2.panel.example.com']])
            ->andReturnUsing(function () {
                $this->zones['firma.cz'] = [];

                return [];
            });

        $page = Livewire::test(SiteDns::class, ['record' => $site->id])->assertSee('Spravovat DNS v panelu');
        $this->pageAction($page, 'createZone', ['domain' => 'other.cz', 'provider' => 'local'])->assertHasErrors();
        $this->pageAction(Livewire::test(SiteDns::class, ['record' => $site->id]), 'createZone', ['domain' => 'firma.cz', 'provider' => 'local'])->assertHasNoErrors();

        $this->assertSame('firma.cz', ManageSiteDns::zoneFor('www.firma.cz')?->domain);
        $this->assertContains('www.firma.cz A', $this->set);
    }

    public function test_a_new_alias_lands_in_its_zone(): void
    {
        $this->zone();
        $site = Site::create(['domain' => 'shop.example.com', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        $this->agent->shouldReceive('call')->with('site.edge', Mockery::any())->andReturn(['edge' => ['aliases' => ['eshop.example.com']]]);

        app(ManageEdge::class)->set($site, ['aliases' => ['eshop.example.com']]);

        $this->assertSame(['eshop.example.com A'], $this->set);
    }
}
