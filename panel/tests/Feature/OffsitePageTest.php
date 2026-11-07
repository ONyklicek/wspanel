<?php

namespace Tests\Feature;

use App\Livewire\Pages\OffsitePage;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class OffsitePageTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private MockInterface $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('sys.versions')->andReturn([])->byDefault();
        $this->agent->shouldReceive('call')->with('offsite.get')->andReturn([
            'configured' => true, 'has_secret' => true,
            'config' => ['enabled' => true, 'endpoint' => 'https://s3.example.com', 'region' => 'auto', 'bucket' => 'zalohy', 'prefix' => 'srv1', 'path_style' => true, 'access_key' => 'AK', 'secret_key' => '', 'keep_days' => 30],
        ])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
    }

    public function test_the_page_shows_the_storage_without_the_secret(): void
    {
        $this->get('/offsite')->assertOk()->assertSee('Zálohy mimo server')->assertSee('zalohy/srv1')->assertSee('zapnuto');
    }

    public function test_saving_keeps_the_secret_when_left_empty(): void
    {
        $this->agent->shouldReceive('call')->once()->with('offsite.set', Mockery::on(fn ($p) => $p['secret_key'] === '' && $p['bucket'] === 'zalohy' && $p['keep_days'] === 14 && $p['enabled'] === true))
            ->andReturn([]);

        $this->pageAction(Livewire::test(OffsitePage::class), 'configure', ['keep_days' => 14])
            ->assertHasNoErrors()->assertNotified('Úložiště funguje');
    }

    public function test_a_refused_test_upload_is_explained_in_czech(): void
    {
        $this->agent->shouldReceive('call')->with('offsite.set', Mockery::any())
            ->andThrow(new AgentException('invalid_params', 'the storage refused a test upload: AccessDenied: Access Denied (HTTP 403)', 'offsite.set'));

        $this->pageAction(Livewire::test(OffsitePage::class), 'configure', ['access_key' => 'WRONG', 'secret_key' => 'x'])
            ->assertNotified('Přístup odepřen');
    }

    public function test_invalid_input_never_reaches_the_agent(): void
    {
        $this->agent->shouldNotReceive('call')->with('offsite.set', Mockery::any());

        $this->pageAction(Livewire::test(OffsitePage::class), 'configure', ['bucket' => 'Bad Bucket', 'keep_days' => 0, 'prefix' => '../x'])
            ->assertHasErrors();
    }
}
