<?php

namespace Tests\Feature;

use App\Livewire\Pages\PhpPage;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class PhpPageTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('php.status')->andReturn([
            'versions' => [
                ['version' => '8.3', 'installed' => true, 'default' => false, 'extensions' => ['intl'], 'available' => ['intl', 'redis']],
                ['version' => '8.4', 'installed' => true, 'default' => true, 'extensions' => ['intl', 'redis'], 'available' => ['imagick', 'intl', 'redis']],
            ],
            'installable' => ['8.5'],
            'frankenphp' => ['version' => 'FrankenPHP v1.13.5 PHP 8.5.12', 'php' => '8.5', 'extensions' => ['core', 'redis']],
        ])->byDefault();
        $this->agent->shouldReceive('call')->with('job.status', Mockery::any())->andReturn(['status' => 'done', 'output' => []])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    public function test_versions_extensions_and_sites_are_shown(): void
    {
        Site::create(['domain' => 'shop.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active']);
        Site::create(['domain' => 'fast.example.com', 'mode' => 'worker', 'framework' => 'laravel', 'php_version' => '8.5', 'status' => 'active']);

        $this->get('/php')->assertOk()
            ->assertSee('PHP 8.4')->assertSee('výchozí pro nové weby')->assertSee('shop.example.com')
            ->assertSee('redis')->assertSee('FrankenPHP')->assertSee('fast.example.com');
    }

    public function test_an_extension_is_installed(): void
    {
        $this->agent->shouldReceive('call')->once()->with('php.extension', ['version' => '8.4', 'extension' => 'imagick', 'action' => 'install'])->andReturn(['job_id' => 'j1']);

        $this->pageAction(Livewire::test(PhpPage::class), 'addExtension_8_4', ['extension' => 'imagick'])
            ->assertHasNoErrors()
            ->assertNotified('PHP 8.4: imagick nainstalováno');
    }

    public function test_only_offered_extensions_can_be_chosen(): void
    {
        $this->agent->shouldNotReceive('call')->with('php.extension', Mockery::any());

        $this->pageAction(Livewire::test(PhpPage::class), 'addExtension_8_4', ['extension' => 'redis'])->assertHasErrors(); // already installed
        $this->pageAction(Livewire::test(PhpPage::class), 'removeExtension_8_3', ['extension' => 'fpm'])->assertHasErrors();
    }

    public function test_a_php_version_is_installed(): void
    {
        $this->agent->shouldReceive('call')->once()->with('php.install', ['version' => '8.5'])->andReturn(['job_id' => 'j2']);

        $this->pageAction(Livewire::test(PhpPage::class), 'installVersion', ['version' => '8.5'])
            ->assertHasNoErrors()
            ->assertNotified('PHP 8.5 nainstalováno');
    }
}
