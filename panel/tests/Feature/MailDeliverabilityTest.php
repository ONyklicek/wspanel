<?php

namespace Tests\Feature;

use App\Models\MailDomain;
use App\Services\Agent\AgentClient;
use App\Services\Dns\Resolver;
use App\Services\Mail\Deliverability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MailDeliverabilityTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'v=DKIM1; k=rsa; p=MIIBIjANBgkq';

    /** @param  array<string, list<string>>  $records  "name|type" => values */
    private function checks(array $records, ?string $ptr): array
    {
        config(['panel.mail_host' => 'mail.hosting.cz', 'panel.public_ipv4' => '203.0.113.7']);
        $this->app->instance(Resolver::class, new class($records, $ptr) extends Resolver
        {
            public function __construct(private array $records, private ?string $ptr) {}

            public function records(string $name, int $type, string $field): array
            {
                return $this->records[$name.'|'.$type] ?? [];
            }

            public function reverse(string $ip): ?string
            {
                return $this->ptr;
            }
        });
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('mail.dkim', ['domain' => 'firma.cz', 'action' => 'get'])
            ->andReturn(['name' => 'wspanel._domainkey.firma.cz', 'value' => self::KEY]);
        $this->app->instance(AgentClient::class, $agent);

        return collect(app(Deliverability::class)->check(MailDomain::create(['domain' => 'firma.cz'])))->keyBy('label')->all();
    }

    public function test_a_domain_set_up_right_passes_every_check(): void
    {
        $c = $this->checks([
            'firma.cz|'.DNS_MX => ['mail.hosting.cz'],
            'mail.hosting.cz|'.DNS_A => ['203.0.113.7'],
            'firma.cz|'.DNS_TXT => ['v=spf1 mx -all'],
            'wspanel._domainkey.firma.cz|'.DNS_TXT => [self::KEY],
            '_dmarc.firma.cz|'.DNS_TXT => ['v=DMARC1; p=quarantine'],
            'webmail.firma.cz|'.DNS_A => ['203.0.113.7'],
            'autoconfig.firma.cz|'.DNS_A => ['203.0.113.7'],
            'autodiscover.firma.cz|'.DNS_A => ['203.0.113.7'],
        ], 'mail.hosting.cz');

        foreach ($c as $label => $check) {
            $this->assertTrue($check['ok'], "{$label}: {$check['detail']}");
        }
    }

    public function test_it_names_what_is_wrong(): void
    {
        $c = $this->checks([
            'firma.cz|'.DNS_MX => ['mx.jinde.cz'],
            'wspanel._domainkey.firma.cz|'.DNS_TXT => ['v=DKIM1; k=rsa; p=JINY'],
            '7.113.0.203.bl.spamcop.net|'.DNS_A => ['127.0.0.2'],
            '7.113.0.203.zen.spamhaus.org|'.DNS_A => ['127.255.255.254'],
        ], null);

        $this->assertFalse($c['MX (příchozí pošta)']['ok']);
        $this->assertStringContainsString('má být mail.hosting.cz', $c['MX (příchozí pošta)']['detail']);
        $this->assertFalse($c['Reverzní záznam (PTR) 203.0.113.7']['ok']);
        $this->assertFalse($c['SPF']['ok']);
        $this->assertSame('nesouhlasí', $c['DKIM']['bad']);
        $this->assertFalse($c['Blacklist bl.spamcop.net']['ok'], 'listed');
        $this->assertNull($c['Blacklist zen.spamhaus.org']['ok'], 'Spamhaus refusing a public resolver is not a listing');
        $this->assertTrue($c['Blacklist b.barracudacentral.org']['ok']);
    }
}
