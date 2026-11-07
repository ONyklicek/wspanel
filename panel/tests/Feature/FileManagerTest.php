<?php

namespace Tests\Feature;

use App\Livewire\Sites\FileBrowser;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class FileManagerTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private MockInterface $agent;

    private Site $site;

    private string $artifacts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artifacts = sys_get_temp_dir().'/wspanel-artifacts-'.uniqid();
        mkdir($this->artifacts);
        config(['agent.artifact_dir' => $this->artifacts]);
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('site.files', Mockery::on(fn ($p) => $p['action'] === 'list'))->andReturnUsing(fn ($op, $p) => ['entries' => $p['path'] === '.'
            ? [['name' => 'current', 'type' => 'link', 'size' => 0, 'mtime' => 1794000000, 'mode' => '0777', 'target' => 'releases/1'], ['name' => 'shared', 'type' => 'dir', 'size' => 0, 'mtime' => 1794000000, 'mode' => '0750']]
            : [['name' => '.env', 'type' => 'file', 'size' => 12, 'mtime' => 1794000000, 'mode' => '0600']]])->byDefault();
        $this->agent->shouldReceive('call')->with('sys.versions')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
        $this->site = Site::create(['domain' => 'app.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active', 'system_user' => 'web_app_example_com']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->artifacts));
        parent::tearDown();
    }

    private function browser(): Testable
    {
        return Livewire::test(FileBrowser::class, ['site' => $this->site->id]);
    }

    public function test_the_tab_lists_the_home_and_opens_folders(): void
    {
        $this->get("/sites/{$this->site->id}/files")->assertOk()->assertSee('web_app_example_com')->assertSee('shared');

        $this->browser()->assertSee('current')->assertSee('→ releases/1')
            ->call('executeTableAction', 'shared', 'open')
            ->assertSet('path', 'shared')
            ->assertSee('.env')
            ->call('up')->assertSet('path', '.');
    }

    public function test_editing_saves_with_the_version_it_opened(): void
    {
        $this->agent->shouldReceive('call')->once()->with('site.files', ['domain' => 'app.example.com', 'action' => 'read', 'path' => 'shared/.env'])
            ->andReturn(['content' => "APP_KEY=x\n", 'mtime' => 1794000000, 'size' => 10]);
        $this->agent->shouldReceive('call')->once()->with('site.files', ['domain' => 'app.example.com', 'action' => 'write', 'path' => 'shared/.env', 'content' => "APP_KEY=y\n", 'if_mtime' => 1794000000])
            ->andReturn(['ok' => true]);

        $b = $this->browser()->call('go', 'shared');
        $this->tableAction($b, '.env', 'edit', ['content' => "APP_KEY=y\n"])->assertHasNoErrors()->assertNotified('Uloženo');
    }

    public function test_a_conflict_is_reported(): void
    {
        $this->agent->shouldReceive('call')->with('site.files', Mockery::on(fn ($p) => $p['action'] === 'read'))->andReturn(['content' => 'a', 'mtime' => 5, 'size' => 1]);
        $this->agent->shouldReceive('call')->with('site.files', Mockery::on(fn ($p) => $p['action'] === 'write'))
            ->andThrow(new AgentException('conflict', 'the file changed since it was opened', 'site.files'));

        $b = $this->browser()->call('go', 'shared');
        $this->tableAction($b, '.env', 'edit', ['content' => 'b'])->assertNotified('Uložení selhalo', 'error');
    }

    public function test_new_folder_and_file_names_are_checked(): void
    {
        $this->agent->shouldReceive('call')->once()->with('site.files', ['domain' => 'app.example.com', 'action' => 'mkdir', 'path' => 'shared/img'])->andReturn(['ok' => true]);
        $this->agent->shouldNotReceive('call')->with('site.files', Mockery::on(fn ($p) => $p['action'] === 'write'));

        $b = $this->browser()->call('go', 'shared');
        $this->headerTableAction($b, 'newFolder', ['name' => 'img'])->assertHasNoErrors();
        foreach (['../x', 'a/b', '..'] as $bad) {
            $this->headerTableAction($this->browser(), 'newFile', ['name' => $bad])->assertHasErrors();
        }
    }

    public function test_upload_hands_the_file_to_the_agent(): void
    {
        $sent = null;
        $this->agent->shouldReceive('call')->once()->with('site.files', Mockery::on(function ($p) use (&$sent) {
            if ($p['action'] !== 'upload') {
                return false;
            }
            $sent = $p;

            return true;
        }))->andReturnUsing(function ($op, $p) {
            $this->assertSame('hello', file_get_contents($this->artifacts.'/'.$p['upload']));

            return ['ok' => true];
        });

        $file = UploadedFile::fake()->createWithContent('readme.txt', 'hello');
        $b = $this->browser();
        $b->call('openHeaderActionModal', 'upload');
        $b->set('tableState.modal.actions.0.data.files', [$file]);
        $b->call('submitActionModal')->assertHasNoErrors()->assertNotified('Nahráno 1 souborů');

        $this->assertSame('readme.txt', $sent['path']);
        $this->assertMatchesRegularExpression('/^up-[0-9a-f]{32}$/', $sent['upload']);
        $this->assertFileDoesNotExist($this->artifacts.'/'.$sent['upload']);
    }

    public function test_download_streams_and_removes_the_copy(): void
    {
        $name = 'dl-'.str_repeat('ab', 16);
        file_put_contents($this->artifacts.'/'.$name, 'APP_KEY=x');
        $this->agent->shouldReceive('call')->once()->with('site.files', ['domain' => 'app.example.com', 'action' => 'download', 'path' => 'shared/.env'])
            ->andReturn(['download' => $name, 'size' => 9]);

        $r = $this->get(route('site-files.download', [$this->site, 'path' => 'shared/.env']));
        $r->assertOk();
        $this->assertSame('APP_KEY=x', $r->streamedContent());
        $this->assertFileDoesNotExist($this->artifacts.'/'.$name);

        auth()->logout();
        $this->get(route('site-files.download', [$this->site, 'path' => 'shared/.env']))->assertRedirect('/login');
    }

    public function test_the_env_file_has_its_own_button(): void
    {
        $this->agent->shouldReceive('call')->once()->with('site.files', ['domain' => 'app.example.com', 'action' => 'read', 'path' => 'shared/.env'])
            ->andReturn(['content' => "APP_ENV=production\n", 'mtime' => 7, 'size' => 19]);
        $this->agent->shouldReceive('call')->once()->with('site.files', ['domain' => 'app.example.com', 'action' => 'write', 'path' => 'shared/.env', 'content' => "APP_ENV=local\n", 'if_mtime' => 7])
            ->andReturn(['ok' => true]);

        $this->headerTableAction($this->browser(), 'editEnv', ['content' => "APP_ENV=local\n"])
            ->assertHasNoErrors()->assertNotified('shared/.env');
    }

    public function test_symfony_edits_env_local_and_a_missing_file_is_created(): void
    {
        $this->site->update(['framework' => 'symfony']);
        $this->agent->shouldReceive('call')->once()->with('site.files', Mockery::on(fn ($p) => $p['action'] === 'read' && $p['path'] === 'shared/.env.local'))
            ->andThrow(new AgentException('not_found', 'not found: shared/.env.local', 'site.files'));
        $this->agent->shouldReceive('call')->once()->with('site.files', ['domain' => 'app.example.com', 'action' => 'write', 'path' => 'shared/.env.local', 'content' => "APP_SECRET=x\n"])
            ->andReturn(['ok' => true]);

        $this->headerTableAction($this->browser(), 'editEnv', ['content' => "APP_SECRET=x\n"])->assertHasNoErrors();
    }
}
