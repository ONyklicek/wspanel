<?php

namespace Tests\Feature;

use App\Actions\Clients\ManageClients;
use App\Actions\Mail\ManageMailHosting;
use App\Exceptions\LimitReachedException;
use App\Models\Client;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Package;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Dns\DnsAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class MailHostingTest extends TestCase
{
    use RefreshDatabase;

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
}
