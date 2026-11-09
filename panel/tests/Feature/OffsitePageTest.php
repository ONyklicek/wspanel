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
        $this->agent->shouldReceive('call')->with('server.backups')->andReturn(['backups' => []])->byDefault();
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

    public function test_ftp_storage_sends_only_its_own_fields(): void
    {
        $this->agent->shouldReceive('call')->once()->with('offsite.set', [
            'enabled' => true, 'type' => 'ftp', 'prefix' => 'srv1', 'keep_days' => 30,
            'host' => 'nas.example.com', 'port' => 21, 'username' => 'backup', 'password' => 'pw',
            'ftp_tls' => 'explicit', 'tls_pin' => '',
        ])->andReturn([]);

        $this->pageAction(Livewire::test(OffsitePage::class), 'configure', ['type' => 'ftp', 'host' => 'nas.example.com', 'port' => 21, 'username' => 'backup', 'password' => 'pw', 'ftp_tls' => 'explicit'])
            ->assertHasNoErrors()->assertNotified('Úložiště funguje');
    }

    public function test_smb_storage_requires_a_share(): void
    {
        $this->agent->shouldNotReceive('call')->with('offsite.set', Mockery::any());

        $this->pageAction(Livewire::test(OffsitePage::class), 'configure', ['type' => 'smb', 'host' => 'nas', 'username' => 'u', 'password' => 'p', 'share' => ''])
            ->assertHasErrors();
    }

    public function test_an_untrusted_certificate_names_its_fingerprint(): void
    {
        $this->agent->shouldReceive('call')->with('offsite.set', Mockery::any())->andThrow(new AgentException('invalid_params',
            "the storage refused a test upload: connecting to the storage failed: the server's certificate is not trusted (x509: certificate signed by unknown authority); its SHA-256 fingerprint is AB:CD:EF", 'offsite.set'));

        $this->pageAction(Livewire::test(OffsitePage::class), 'configure', ['type' => 'ftp', 'host' => 'nas', 'username' => 'u', 'password' => 'p', 'ftp_tls' => 'explicit'])
            ->assertNotified('AB:CD:EF');
    }

    public function test_the_signing_key_can_be_shown_to_keep_it(): void
    {
        $this->agent->shouldReceive('call')->once()->with('offsite.signing_key')->andReturn(['signing_key' => str_repeat('ab', 32)]);

        $this->pageAction(Livewire::test(OffsitePage::class), 'signingKey')->assertNotified(str_repeat('ab', 32));
    }

    public function test_server_backups_are_listed_and_made_on_request(): void
    {
        $this->agent->shouldReceive('call')->with('server.backups')->andReturn(['backups' => [
            ['file' => 'server-20261109T021800Z.wspbak', 'size' => 52_000, 'created' => '2026-11-09T02:18:00Z'],
        ]]);
        $this->agent->shouldReceive('call')->once()->with('server.backup')->andReturn(['job_id' => 'abcdef0123456789']);

        $page = Livewire::test(OffsitePage::class)->assertSee('server-20261109T021800Z.wspbak');
        $this->pageAction($page, 'backupServer')->assertNotified('Záloha serveru běží');
    }

    public function test_the_nightly_server_backup_asks_the_agent(): void
    {
        $this->agent->shouldReceive('call')->once()->with('server.backup')->andReturn(['job_id' => 'abcdef0123456789']);

        $this->artisan('panel:server-backup')->expectsOutputToContain('job abcdef0123456789')->assertSuccessful();
    }
}
