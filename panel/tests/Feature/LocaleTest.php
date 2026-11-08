<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Support\AgentMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $agent);
    }

    public function test_the_panel_speaks_czech_by_default(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/sites')->assertOk()->assertSee('Weby')->assertSee('Zabezpečení')->assertSee('English');
    }

    public function test_a_user_who_chose_english_gets_english(): void
    {
        $this->actingAs(User::factory()->create(['locale' => 'en']));
        Site::create(['domain' => 'app.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active']);

        $this->get('/sites')->assertOk()
            ->assertSee('Sites')->assertSee('Security')->assertSee('New site')->assertSee('Čeština')
            ->assertDontSee('Zabezpečení');
        $this->get('/notification-channels')->assertOk()->assertSee('Notifications')->assertSee('Add channel');
        $this->get('/modules')->assertOk()->assertSee('Clients and packages')->assertSee('Install XHProf + XHGui');
    }

    public function test_the_user_menu_switches_the_language(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post('/locale/en')->assertRedirect();
        $this->assertSame('en', $user->fresh()->locale);
        $this->post('/locale/de')->assertNotFound();
    }

    public function test_agent_messages_stay_the_agents_own_words_in_english(): void
    {
        app()->setLocale('en');
        $this->assertSame('mailbox info@x.cz does not exist', AgentMessages::cs('mailbox info@x.cz does not exist'));
        app()->setLocale('cs');
        $this->assertSame('Schránka info@x.cz neexistuje.', AgentMessages::cs('mailbox info@x.cz does not exist'));
    }
}
