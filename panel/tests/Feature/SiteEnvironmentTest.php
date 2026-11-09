<?php

namespace Tests\Feature;

use App\Livewire\Sites\SiteEnvironment;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SiteEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $agent;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
        $this->site = Site::create(['domain' => 'shop.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active', 'env_secrets' => ['STRIPE_SECRET']]);
        $this->agent->shouldReceive('call')->with('env.vars', Mockery::on(fn ($p) => $p['domain'] === 'shop.example.com' && is_array($p['secrets'])))->andReturn([
            'vars' => ['APP_NAME' => 'Shop', 'STRIPE_SECRET' => ['set' => true]],
            'managed' => ['DB_PASSWORD'], 'panel_set' => [],
        ])->byDefault();
    }

    public function test_variables_show_values_and_secrets_never_do(): void
    {
        Livewire::test(SiteEnvironment::class, ['record' => $this->site->id])
            ->assertSee('APP_NAME')->assertSee('Shop')->assertSee('STRIPE_SECRET')->assertSee('••••')->assertSee('DB_PASSWORD');
    }

    public function test_a_secret_is_written_and_remembered_as_secret(): void
    {
        $this->agent->shouldReceive('call')->once()->with('env.vars_set', ['domain' => 'shop.example.com', 'secrets' => ['MAIL_TOKEN' => 'abc123']])->andReturn([]);

        Livewire::test(SiteEnvironment::class, ['record' => $this->site->id])
            ->set('key', 'MAIL_TOKEN')->set('secret', true)->set('value', '')->call('save')->assertHasErrors('value')
            ->set('value', 'abc123')->call('save')->assertHasNoErrors()->assertNotified();
        $this->assertSame(['STRIPE_SECRET', 'MAIL_TOKEN'], $this->site->fresh()->env_secrets);
    }

    public function test_the_panels_keys_and_bad_names_are_refused(): void
    {
        $this->agent->shouldNotReceive('call')->with('env.vars_set', Mockery::any());

        Livewire::test(SiteEnvironment::class, ['record' => $this->site->id])
            ->set('key', 'DB_PASSWORD')->set('value', 'x')->call('save')->assertHasErrors('key')
            ->set('key', '9BAD')->call('save')->assertHasErrors('key')
            ->set('key', 'APP_NAME')->call('save')->assertHasErrors('key')
            ->set('key', 'NEW_ONE')->set('value', "a\nb")->call('save')->assertHasErrors('value');
    }

    public function test_a_variable_is_edited_and_a_secret_removed(): void
    {
        $this->agent->shouldReceive('call')->once()->with('env.vars_set', ['domain' => 'shop.example.com', 'vars' => ['APP_NAME' => 'Shop 2']])->andReturn([]);
        $this->agent->shouldReceive('call')->once()->with('env.vars_set', ['domain' => 'shop.example.com', 'unset' => ['STRIPE_SECRET']])->andReturn([]);

        Livewire::test(SiteEnvironment::class, ['record' => $this->site->id])
            ->call('edit', 'APP_NAME', false)->assertSet('value', 'Shop')->set('value', 'Shop 2')->call('save')->assertHasNoErrors()
            ->call('edit', 'STRIPE_SECRET', true)->assertSet('value', '')
            ->call('remove', 'STRIPE_SECRET');
        $this->assertSame([], $this->site->fresh()->env_secrets);
    }

    public function test_composer_credentials_are_written_and_listed_without_secrets(): void
    {
        $this->agent->shouldReceive('call')->with('site.composer_auth', ['domain' => 'shop.example.com'])->andReturn(['entries' => [['type' => 'github-oauth', 'host' => 'github.com']]]);
        $this->agent->shouldReceive('call')->once()->with('site.composer_auth_set', ['domain' => 'shop.example.com', 'type' => 'http-basic', 'host' => 'repo.packagist.com', 'username' => 'token', 'secret' => 'pw123'])->andReturn([]);
        $this->agent->shouldReceive('call')->once()->with('site.composer_auth_set', ['domain' => 'shop.example.com', 'type' => 'github-oauth', 'host' => 'github.com', 'remove' => true])->andReturn([]);

        Livewire::test(SiteEnvironment::class, ['record' => $this->site->id])
            ->assertSee('auth.json')->assertSee('github.com')
            ->set('cType', 'http-basic')->set('cHost', 'Repo.Packagist.com')->set('cSecret', 'pw123')->call('saveComposer')->assertHasErrors('cUser')
            ->set('cUser', 'token')->call('saveComposer')->assertHasNoErrors()->assertNotified()
            ->call('removeComposer', 'github-oauth', 'github.com');
    }
}
