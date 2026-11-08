<?php

namespace Tests\Feature;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class WebmailPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'webmail-key-0123456789abcdefghijklmnop';

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $file = tempnam(sys_get_temp_dir(), 'wsp');
        file_put_contents($file, self::KEY."\n");
        config(['panel.webmail_api_key_file' => $file]);
        $this->agent = Mockery::mock(AgentClient::class);
        $this->app->instance(AgentClient::class, $this->agent);
    }

    private function change(array $data, string $key = self::KEY, string $ip = '127.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withToken($key)
            ->postJson('/internal/webmail/password', $data + ['address' => 'Alice@firma.cz', 'current' => 'old-password-1', 'new' => 'new-password-22']);
    }

    public function test_the_webmail_changes_a_mailbox_password_with_the_current_one(): void
    {
        $this->agent->shouldReceive('call')->once()->with('mail.mailbox.password', Mockery::on(fn ($p) => $p['address'] === 'alice@firma.cz'
            && $p['current_password'] === 'old-password-1'
            && str_starts_with($p['password_hash'], '$6$rounds=') && crypt('new-password-22', $p['password_hash']) === $p['password_hash']))
            ->andReturn(['address' => 'alice@firma.cz']);

        $this->change([])->assertOk()->assertJson(['ok' => true]);
    }

    public function test_only_the_webmail_on_this_server_may_ask(): void
    {
        $this->agent->shouldNotReceive('call');

        $this->change([], 'wrong-key-0123456789abcdefghijklmnopqr')->assertForbidden();
        $this->change([], self::KEY, '203.0.113.9')->assertForbidden();
        $this->change(['new' => 'short'])->assertUnprocessable();
    }

    public function test_a_wrong_current_password_is_refused_and_throttled(): void
    {
        $this->agent->shouldReceive('call')->times(5)->andThrow(new AgentException('forbidden', 'wrong address or password', 'mail.mailbox.password'));

        for ($i = 0; $i < 5; $i++) {
            $this->change([])->assertForbidden()->assertJson(['error' => 'wrong_password']);
        }
        $this->change([])->assertStatus(429);
    }
}
