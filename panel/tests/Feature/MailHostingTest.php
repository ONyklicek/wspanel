<?php

namespace Tests\Feature;

use App\Actions\Clients\ManageClients;
use App\Actions\Dns\ManageDnsZones;
use App\Actions\Mail\ManageMailHosting;
use App\Actions\Sites\CreateSite;
use App\Actions\Sites\ManageEdge;
use App\Exceptions\LimitReachedException;
use App\Livewire\Mail\MailboxTable;
use App\Livewire\Mail\ViewMailDomain;
use App\Livewire\Sites\SiteMail;
use App\Models\Client;
use App\Models\DnsZone;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Package;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Dns\DnsAdvisor;
use App\Services\Dns\Resolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class MailHostingTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('mail.hosting')->andReturn(['installed' => true])->byDefault();
        $this->agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
    }

    public function test_a_mailbox_password_leaves_the_panel_hashed_only(): void
    {
        $this->actingAs(User::factory()->create());
        $domain = MailDomain::create(['domain' => 'firma.cz']);
        $sent = null;
        $this->agent->shouldReceive('call')->once()->with('mail.mailbox.set', Mockery::on(function ($p) use (&$sent) {
            $sent = $p;

            return true;
        }))->andReturn(['created' => true]);

        app(ManageMailHosting::class)->saveMailbox($domain, 'Alice', 'Tajne-heslo-123', 500, true);

        $this->assertSame('alice@firma.cz', $sent['address']);
        $this->assertStringStartsWith('$6$rounds=', $sent['password_hash']);
        $this->assertSame($sent['password_hash'], crypt('Tajne-heslo-123', $sent['password_hash']), 'the hash verifies the password');
        $this->assertStringNotContainsString('Tajne-heslo-123', json_encode($sent));
        $this->assertSame(500, Mailbox::sole()->quota_mb);
    }

    public function test_the_package_limits_mailboxes_and_their_space(): void
    {
        $client = Client::factory()->for(Package::factory()->state(['max_mailboxes' => 1, 'max_mail_quota_mb' => 1000]))->create();
        $this->actingAs(User::factory()->forClient($client)->create());
        $domain = MailDomain::create(['domain' => 'klient.cz', 'client_id' => $client->id]);
        $mail = app(ManageMailHosting::class);

        $mail->saveMailbox($domain, 'a', 'Heslo-1234567', 800, true);

        try {
            $mail->saveMailbox($domain, 'b', 'Heslo-1234567', 100, true);
            $this->fail('the second mailbox is over the package');
        } catch (LimitReachedException $e) {
            $this->assertStringContainsString('Poštovní schránky 1 z 1', $e->getMessage());
        }
        $this->expectExceptionMessage('Místo pro poštu');
        $mail->saveMailbox($domain, 'a', null, 1200, true, Mailbox::sole());
    }

    public function test_a_client_takes_mail_only_for_a_domain_of_their_own(): void
    {
        [$alice, $bob] = Client::factory()->count(2)->create();
        Site::acrossClients()->create(['domain' => 'alice.cz', 'client_id' => $alice->id, 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        Site::acrossClients()->create(['domain' => 'shop.bob.cz', 'client_id' => $bob->id, 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        $this->actingAs(User::factory()->forClient($alice)->create());
        $mail = app(ManageMailHosting::class);

        $this->assertSame($alice->id, $mail->addDomain('alice.cz')->client_id);
        foreach (['bob.cz' => 'jiného vlastníka', 'nikoho.cz' => 'vašeho webu'] as $domain => $why) {
            try {
                $mail->addDomain($domain);
                $this->fail("{$domain} must be refused");
            } catch (AgentException $e) {
                $this->assertStringContainsString($why, $e->getMessage());
            }
        }
        $this->assertFalse(MailDomain::acrossClients()->where('domain', 'bob.cz')->exists());
    }

    public function test_a_subdomain_site_is_no_claim_to_the_parent_domains_mail(): void
    {
        [$alice, $bob] = Client::factory()->count(2)->create();
        Site::acrossClients()->create(['domain' => 'bob.cz', 'client_id' => $bob->id, 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        Site::acrossClients()->create(['domain' => 'x.bob.cz', 'client_id' => $alice->id, 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        Site::acrossClients()->create(['domain' => 'shop.carol.cz', 'client_id' => $alice->id, 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        $this->actingAs(User::factory()->forClient($alice)->create());
        $mail = app(ManageMailHosting::class);

        foreach (['bob.cz' => 'jiného vlastníka', 'carol.cz' => 'vašeho webu'] as $domain => $why) {
            try {
                $mail->addDomain($domain);
                $this->fail("{$domain} must be refused");
            } catch (AgentException $e) {
                $this->assertStringContainsString($why, $e->getMessage());
            }
        }
    }

    public function test_a_client_cannot_put_a_site_or_alias_under_another_clients_domain(): void
    {
        [$alice, $bob] = Client::factory()->count(2)->create();
        DnsZone::acrossClients()->create(['domain' => 'bob.cz', 'provider' => 'local', 'client_id' => $bob->id]);
        $own = Site::acrossClients()->create(['domain' => 'alice.cz', 'client_id' => $alice->id, 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        $this->actingAs(User::factory()->forClient($alice)->create());

        try {
            app(CreateSite::class)->handle(['domain' => 'shop.bob.cz', 'framework' => 'php', 'php_version' => '8.4']);
            $this->fail('a site inside another client\'s zone');
        } catch (AgentException $e) {
            $this->assertStringContainsString('jiného vlastníka', $e->getMessage());
        }
        try {
            app(ManageDnsZones::class)->create(['domain' => 'eshop.bob.cz', 'provider' => 'local']);
            $this->fail('a zone inside another client\'s zone');
        } catch (AgentException $e) {
            $this->assertStringContainsString('jiného vlastníka', $e->getMessage());
        }
        $this->expectExceptionMessage('shop.bob.cz: doména (nebo její část) patří jinému vlastníkovi');
        app(ManageEdge::class)->set($own, ['aliases' => ['shop.bob.cz']]);
    }

    public function test_the_administrator_cannot_take_a_clients_mail_domain(): void
    {
        $bob = Client::factory()->create();
        Site::acrossClients()->create(['domain' => 'bob.cz', 'client_id' => $bob->id, 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        $this->actingAs(User::factory()->create());

        $this->expectExceptionMessage('jiného vlastníka');
        app(ManageMailHosting::class)->addDomain('bob.cz');
    }

    public function test_a_sites_mail_domain_moves_with_the_site(): void
    {
        [$alice, $bob] = Client::factory()->count(2)->create();
        $site = Site::acrossClients()->create(['domain' => 'firma.cz', 'client_id' => $alice->id, 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        MailDomain::acrossClients()->create(['domain' => 'firma.cz', 'client_id' => $alice->id]);
        $this->actingAs(User::factory()->create());

        app(ManageClients::class)->assignSite($site, $bob);

        $this->assertSame($bob->id, MailDomain::acrossClients()->sole()->client_id);
    }

    public function test_suspending_a_client_blocks_its_mailboxes_and_resuming_brings_back_only_those(): void
    {
        $client = Client::factory()->create();
        $domain = MailDomain::acrossClients()->create(['domain' => 'klient.cz', 'client_id' => $client->id]);
        $active = Mailbox::withoutGlobalScopes()->create(['mail_domain_id' => $domain->id, 'local_part' => 'info', 'quota_mb' => 100, 'enabled' => true]);
        $alreadyBlocked = Mailbox::withoutGlobalScopes()->create(['mail_domain_id' => $domain->id, 'local_part' => 'old', 'quota_mb' => 100, 'enabled' => false]);
        $sent = [];
        $this->agent->shouldReceive('call')->with('mail.mailbox.set', Mockery::on(function ($p) use (&$sent) {
            $sent[] = $p['address'].'='.($p['enabled'] ? 'on' : 'off');

            return true;
        }))->andReturn([]);
        $this->actingAs(User::factory()->create());
        $clients = app(ManageClients::class);

        $clients->suspend($client);
        $this->assertFalse($active->fresh()->enabled);

        $clients->resume($client->fresh());
        $this->assertTrue($active->fresh()->enabled);
        $this->assertFalse($alreadyBlocked->fresh()->enabled, 'a mailbox blocked before stays blocked');
        $this->assertSame(['info@klient.cz=off', 'info@klient.cz=on'], $sent);
    }

    public function test_a_client_does_not_see_another_clients_mail(): void
    {
        [$alice, $bob] = Client::factory()->count(2)->create();
        $bobs = MailDomain::acrossClients()->create(['domain' => 'bob.cz', 'client_id' => $bob->id]);
        MailDomain::acrossClients()->create(['domain' => 'alice.cz', 'client_id' => $alice->id]);
        $this->actingAs(User::factory()->forClient($alice)->create());

        $this->get('/mail-domains')->assertOk()->assertSee('alice.cz')->assertDontSee('bob.cz');
        $this->get("/mail-domains/{$bobs->id}")->assertNotFound();
    }

    public function test_aliases_replace_the_domains_set(): void
    {
        $this->actingAs(User::factory()->create());
        $domain = MailDomain::create(['domain' => 'firma.cz']);
        $this->agent->shouldReceive('call')->once()->with('mail.alias.set', Mockery::on(fn ($p) => $p['domain'] === 'firma.cz' && (array) $p['aliases'] === ['info' => ['a@firma.cz'], '' => ['a@firma.cz']]))->andReturn([]);

        app(ManageMailHosting::class)->setAliases($domain, ['info' => ['a@firma.cz'], '' => ['a@firma.cz']]);

        $this->assertSame(2, $domain->aliases()->count());
    }

    public function test_dns_recommends_mx_and_client_discovery_for_a_mail_domain(): void
    {
        config(['app.url' => 'https://panel.example.com']);
        MailDomain::create(['domain' => 'firma.cz']);

        $records = collect(DnsAdvisor::recommended('firma.cz'));

        $this->assertSame(['10 panel.example.com.'], $records->firstWhere('type', 'MX')['records']);
        $this->assertTrue($records->contains(fn ($r) => $r['name'] === '_imaps._tcp.firma.cz' && $r['records'] === ['0 1 993 panel.example.com.']));
        $cnames = $records->where('type', 'CNAME')->pluck('name')->all();
        $this->assertSame(['webmail.firma.cz', 'autoconfig.firma.cz', 'autodiscover.firma.cz'], $cnames);
    }

    public function test_the_webmail_opens_signed_in_without_the_token_in_the_url(): void
    {
        $this->actingAs(User::factory()->create());
        $mailbox = Mailbox::create(['mail_domain_id' => MailDomain::create(['domain' => 'firma.cz'])->id, 'local_part' => 'alice', 'quota_mb' => 100, 'enabled' => true]);
        $token = str_repeat('a', 64);
        $this->agent->shouldReceive('call')->once()->with('mail.webmail.login', ['address' => 'alice@firma.cz'])
            ->andReturn(['url' => 'https://webmail.firma.cz/?_task=login', 'token' => $token]);

        $this->get("/mailboxes/{$mailbox->id}/webmail")
            ->assertOk()
            ->assertSee('action="https://webmail.firma.cz/?_task=login"', false)
            ->assertSee('name="_wsp_sso" value="'.$token.'"', false)
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_a_client_cannot_open_another_clients_mailbox(): void
    {
        [$alice, $bob] = Client::factory()->count(2)->create();
        $bobs = Mailbox::withoutGlobalScopes()->create(['mail_domain_id' => MailDomain::acrossClients()->create(['domain' => 'bob.cz', 'client_id' => $bob->id])->id,
            'local_part' => 'info', 'quota_mb' => 100, 'enabled' => true]);
        $this->actingAs(User::factory()->forClient($alice)->create());
        $this->agent->shouldNotReceive('call')->with('mail.webmail.login', Mockery::any());

        $this->get("/mailboxes/{$bobs->id}/webmail")->assertNotFound();
        $this->get("/mailboxes/{$bobs->id}/apple.mobileconfig")->assertNotFound();
    }

    public function test_the_apple_profile_holds_the_servers_but_no_password(): void
    {
        config(['panel.mail_host' => 'mail.example.com']);
        $this->actingAs(User::factory()->create());
        $mailbox = Mailbox::create(['mail_domain_id' => MailDomain::create(['domain' => 'firma.cz'])->id, 'local_part' => 'alice', 'quota_mb' => 100, 'enabled' => true]);

        $response = $this->get("/mailboxes/{$mailbox->id}/apple.mobileconfig")->assertOk()
            ->assertHeader('Content-Type', 'application/x-apple-aspen-config');

        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml, 'a valid plist');
        $this->assertStringContainsString('<string>mail.example.com</string>', $response->getContent());
        $this->assertStringContainsString('<string>alice@firma.cz</string>', $response->getContent());
        $this->assertStringNotContainsString('Password</key><string>', $response->getContent());
    }

    public function test_webmail_is_left_off_where_a_site_already_serves_its_address(): void
    {
        $this->actingAs(User::factory()->create());
        Site::create(['domain' => 'firma.cz', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        Site::create(['domain' => 'webmail.firma.cz', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        $this->agent->shouldReceive('call')->once()->with('mail.domain.set', ['domain' => 'firma.cz', 'enabled' => true, 'webmail' => false, 'autoconfig' => true])->andReturn([]);

        $domain = app(ManageMailHosting::class)->addDomain('firma.cz');

        $this->assertFalse($domain->webmail);
        $this->expectExceptionMessage('webmail.firma.cz už patří webu');
        app(ManageMailHosting::class)->setFeatures($domain, true, true);
    }

    public function test_a_site_cannot_take_a_mail_domains_webmail_address(): void
    {
        MailDomain::create(['domain' => 'firma.cz']);

        $this->assertTrue(Site::hostTaken('webmail.firma.cz'));
        $this->assertTrue(Site::hostTaken('autodiscover.firma.cz'));
        $this->assertFalse(Site::hostTaken('shop.firma.cz'));
    }

    public function test_sender_lists_are_set_from_the_domain_page(): void
    {
        $this->actingAs(User::factory()->create());
        $domain = MailDomain::create(['domain' => 'firma.cz']);
        $this->agent->shouldReceive('call')->once()->with('mail.spam.set', ['domain' => 'firma.cz', 'spam' => [
            'allow' => ['boss@partner.cz', '@bank.cz'], 'block' => ['@spam.example'],
        ]])->andReturn([]);

        $page = Livewire::test(ViewMailDomain::class, ['record' => $domain->id]);
        $this->pageAction($page, 'spam', ['allow' => "boss@partner.cz\n@bank.cz", 'block' => '@spam.example'])
            ->assertHasNoErrors();

        $this->assertSame(['@spam.example'], $domain->fresh()->spam['block']);
    }

    public function test_the_deliverability_check_opens_on_the_domain_page(): void
    {
        $this->actingAs(User::factory()->create());
        $domain = MailDomain::create(['domain' => 'firma.cz']);
        $this->app->instance(Resolver::class, new class extends Resolver
        {
            public function records(string $name, int $type, string $field): array
            {
                return [];
            }

            public function reverse(string $ip): ?string
            {
                return null;
            }
        });

        $page = Livewire::test(ViewMailDomain::class, ['record' => $domain->id])
            ->call('mountAction', 'deliverability');

        $page->assertSee('MX (příchozí pošta)')->assertSee('DMARC');
    }

    public function test_a_mailbox_forwards_with_or_without_a_copy(): void
    {
        $this->actingAs(User::factory()->create());
        $domain = MailDomain::create(['domain' => 'firma.cz']);
        $alice = Mailbox::create(['mail_domain_id' => $domain->id, 'local_part' => 'alice', 'quota_mb' => 500, 'enabled' => true]);
        $this->agent->shouldReceive('call')->once()->with('mail.forward.set', ['address' => 'alice@firma.cz', 'to' => ['bob@firma.cz'], 'keep_copy' => false])->andReturn([]);
        $this->agent->shouldReceive('call')->once()->with('mail.forward.set', ['address' => 'alice@firma.cz', 'to' => [], 'keep_copy' => true])->andReturn([]);
        $table = Livewire::test(MailboxTable::class, ['domain' => $domain->id]);

        $this->tableAction($table, $alice, 'forward', ['to' => ['Bob@firma.cz'], 'keep_copy' => '0'])->assertNotified();
        $this->assertSame(['to' => ['bob@firma.cz'], 'keep_copy' => false], $alice->fresh()->forward);
        $table->assertSee('→ bob@firma.cz (bez kopie)');

        $this->tableAction($table, $alice, 'forward', ['to' => [], 'keep_copy' => '1']);
        $this->assertNull($alice->fresh()->forward);
    }

    /** @param  array<string, list<string>>  $records  "name|type" => values, changeable later through the reference */
    private function fakeDns(array &$records): void
    {
        config(['panel.mail_host' => 'mail.hosting.cz', 'panel.public_ipv4' => '203.0.113.7']);
        $this->app->instance(Resolver::class, new class($records) extends Resolver
        {
            public function __construct(public array &$records) {}

            public function records(string $name, int $type, string $field): array
            {
                return $this->records[$name.'|'.$type] ?? [];
            }
        });
    }

    public function test_forwarding_off_the_server_waits_for_the_mail_hosts_spf_or_a_confirmation(): void
    {
        $this->actingAs(User::factory()->create());
        $dns = [];
        $this->fakeDns($dns);
        $domain = MailDomain::create(['domain' => 'firma.cz']);
        $alice = Mailbox::create(['mail_domain_id' => $domain->id, 'local_part' => 'alice', 'quota_mb' => 500, 'enabled' => true]);
        $this->agent->shouldReceive('call')->twice()->with('mail.forward.set', Mockery::any())->andReturn([]);
        $table = fn () => Livewire::test(MailboxTable::class, ['domain' => $domain->id]);

        $this->tableAction($table(), $alice, 'forward', ['to' => ['petr@seznam.cz'], 'keep_copy' => '1'])->assertHasErrors()
            ->assertSee('V DNS zatím chybí')->assertSee('mail.hosting.cz TXT');
        $this->assertNull($alice->fresh()->forward);

        $this->tableAction($table(), $alice, 'forward', ['to' => ['petr@seznam.cz'], 'keep_copy' => '1', 'dns_anyway' => true])->assertHasNoErrors();
        $this->assertSame(['petr@seznam.cz'], $alice->fresh()->forward['to']);

        $dns['mail.hosting.cz|'.DNS_TXT] = ['v=spf1 a -all'];
        $this->tableAction($table(), $alice, 'forward', ['to' => ['jana@seznam.cz'], 'keep_copy' => '1'])->assertHasNoErrors();
    }

    public function test_webmail_waits_for_its_dns_or_a_confirmation(): void
    {
        $this->actingAs(User::factory()->create());
        $dns = [];
        $this->fakeDns($dns);
        $domain = MailDomain::create(['domain' => 'firma.cz', 'webmail' => false, 'autoconfig' => false]);
        $this->agent->shouldReceive('call')->once()->with('mail.domain.set', Mockery::on(fn ($p) => $p['webmail'] === true))->andReturn([]);

        $this->pageAction(Livewire::test(ViewMailDomain::class, ['record' => $domain->id]), 'features', ['webmail' => true, 'autoconfig' => false])
            ->assertHasErrors();

        $dns['webmail.firma.cz|'.DNS_A] = ['203.0.113.7'];
        $this->app->forgetScopedInstances(); // a new request: DNS answers are remembered for one only
        $this->pageAction(Livewire::test(ViewMailDomain::class, ['record' => $domain->id]), 'features', ['webmail' => true, 'autoconfig' => false])
            ->assertHasNoErrors();
    }

    public function test_dns_recommends_an_spf_for_the_mail_host_in_its_zone(): void
    {
        config(['app.url' => 'https://panel.example.com']);
        MailDomain::create(['domain' => 'firma.cz']);

        $spf = collect(DnsAdvisor::recommended('example.com'))->firstWhere('name', 'panel.example.com');

        $this->assertSame(['TXT', ['v=spf1 a -all']], [$spf['type'], $spf['records']]);
        $this->assertNull(collect(DnsAdvisor::recommended('firma.cz'))->firstWhere('name', 'panel.example.com'), 'only the zone the mail host lies in');
    }

    public function test_a_mailbox_cannot_forward_to_itself_or_to_a_non_address(): void
    {
        $this->actingAs(User::factory()->create());
        $domain = MailDomain::create(['domain' => 'firma.cz']);
        $alice = Mailbox::create(['mail_domain_id' => $domain->id, 'local_part' => 'alice', 'quota_mb' => 500, 'enabled' => true]);
        $this->agent->shouldNotReceive('call')->with('mail.forward.set', Mockery::any());
        $table = Livewire::test(MailboxTable::class, ['domain' => $domain->id]);

        $this->tableAction($table, $alice, 'forward', ['to' => ['alice@firma.cz'], 'keep_copy' => '0']);
        $this->tableAction($table, $alice, 'forward', ['to' => ['not an address'], 'keep_copy' => '0']);

        $this->assertNull($alice->fresh()->forward);
    }

    public function test_the_sites_mail_tab_manages_the_domains_mailboxes(): void
    {
        $this->actingAs(User::factory()->create());
        $site = Site::create(['domain' => 'www.firma.cz', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active']);
        $this->agent->shouldReceive('call')->with('mail.hosting')->andReturn(['installed' => true]);

        $page = Livewire::test(SiteMail::class, ['record' => $site->id])->assertSee('Zřídit poštu pro firma.cz');
        $this->agent->shouldReceive('call')->once()->with('mail.domain.set', Mockery::on(fn ($p) => $p['domain'] === 'firma.cz' && $p['enabled']))->andReturn([]);
        $page->call('setUp')->assertNotified();

        $domain = MailDomain::sole();
        $this->assertSame('firma.cz', $domain->domain);
        Mailbox::create(['mail_domain_id' => $domain->id, 'local_part' => 'info', 'quota_mb' => 500, 'enabled' => true]);
        $this->get("/sites/{$site->id}/mail")->assertOk()->assertSee('info@firma.cz')->assertDontSee('Zřídit poštu');
    }
}
