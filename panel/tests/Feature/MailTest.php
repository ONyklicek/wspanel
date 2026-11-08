<?php

namespace Tests\Feature;

use App\Livewire\Pages\MailPage as Mail;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class MailTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('mail.status')->andReturn([
            'default_mode' => 'send', 'relay' => '', 'queue' => 0,
            'services' => [['unit' => 'postfix.service', 'active' => 'active']],
            'sites' => ['app.example.com' => 'capture'],
            'dkim' => [],
        ])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    private function site(): Site
    {
        return Site::create(['domain' => 'app.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active', 'system_user' => 'web_app_example_com']);
    }

    public function test_page_shows_sites_and_syncs_their_mode(): void
    {
        $site = $this->site();

        Livewire::test(Mail::class)->assertOk()->assertSee('app.example.com')->assertSee('zachytává (Mailpit)');
        $this->assertSame('capture', $site->refresh()->mail_mode);
    }

    public function test_switching_a_site_to_send_and_creating_dkim(): void
    {
        $site = $this->site();
        $this->agent->shouldReceive('call')->once()->with('site.mail', ['domain' => 'app.example.com', 'mode' => 'send'])->andReturn(['mode' => 'send']);
        $this->agent->shouldReceive('call')->once()->with('mail.dkim', ['domain' => 'app.example.com', 'action' => 'create'])
            ->andReturn(['domain' => 'app.example.com', 'selector' => 'wspanel', 'name' => 'wspanel._domainkey.app.example.com', 'value' => 'v=DKIM1; k=rsa; p=AAA']);

        Livewire::test(Mail::class)
            ->call('setMode', $site->id, 'send')
            ->assertNotified()
            ->call('createDkim', 'app.example.com')
            ->assertNotified();
    }

    public function test_dkim_only_for_known_sites(): void
    {
        $this->agent->shouldNotReceive('call')->with('mail.dkim', Mockery::any());

        Livewire::test(Mail::class)->call('createDkim', 'evil.example.com')->assertStatus(404);
    }

    public function test_relay_credentials_go_to_the_agent(): void
    {
        $this->agent->shouldReceive('call')->once()
            ->with('mail.relay', ['host' => 'smtp.eu.mailgun.org', 'port' => 587, 'username' => 'postmaster', 'password' => 'key-123'])
            ->andReturn(['relay' => '[smtp.eu.mailgun.org]:587']);

        $this->pageAction(Livewire::test(Mail::class), 'relay', ['host' => 'smtp.eu.mailgun.org', 'port' => 587, 'username' => 'postmaster', 'password' => 'key-123'])
            ->assertHasNoErrors();
    }

    public function test_forward_auth_for_mailpit(): void
    {
        $this->get('/auth/check')->assertNoContent();

        auth()->logout();
        $this->get('/auth/check', ['X-Forwarded-Uri' => '/mailpit/'])->assertRedirect('/login');
        $this->assertStringEndsWith('/mailpit/', session('url.intended'));
    }

    public function test_the_queue_shows_why_mail_waits_and_can_be_cleared(): void
    {
        $this->agent->shouldReceive('call')->with('mail.queue', ['action' => 'list'])->andReturn(['messages' => [
            ['id' => 'A1B2C3D4E5', 'queue' => 'deferred', 'arrival' => 1794100000, 'size' => 900, 'sender' => 'noreply@app.example.com',
                'recipients' => ['who@example.net'], 'reason' => 'connect to mx.example.net: Connection timed out'],
        ]]);
        $this->agent->shouldReceive('call')->once()->with('mail.queue', ['action' => 'delete', 'ids' => ['A1B2C3D4E5']])->andReturn(['deleted' => 1]);

        Livewire::test(Mail::class)
            ->call('loadQueue')
            ->assertSee('who@example.net')
            ->assertSee('Connection timed out')
            ->call('deleteQueued', 'A1B2C3D4E5')
            ->assertNotified();
    }
}
