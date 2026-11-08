<?php

namespace Tests\Feature;

use App\Actions\Mail\ManageMailHosting;
use App\Models\Client;
use App\Models\MailDomain;
use App\Models\Mailbox;
use App\Models\Package;
use App\Models\User;
use App\Services\Agent\AgentClient;
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
        } catch (\App\Exceptions\LimitReachedException $e) {
            $this->assertStringContainsString('Poštovní schránky 1 z 1', $e->getMessage());
        }
        $this->expectExceptionMessage('Místo pro poštu');
        $mail->saveMailbox($domain, 'a', null, 1200, true, Mailbox::sole());
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
    }
}
