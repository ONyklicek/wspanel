<?php

namespace Tests\Feature;

use App\Livewire\Sites\BackupList;
use App\Models\Database;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\RunsWireActions;
use Tests\TestCase;

class SiteBackupsTest extends TestCase
{
    use RefreshDatabase;
    use RunsWireActions;

    private const FILE = 'app.example.com-20261107T023000Z.tar.gz';

    private MockInterface $agent;

    private Site $site;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir().'/wspanel-backups-'.uniqid();
        config(['agent.backup_dir' => $this->backupDir]);
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('site.backups', ['domain' => 'app.example.com'])->andReturn([
            'backups' => [['file' => self::FILE, 'size' => 2_500_000, 'created' => '2026-11-07T02:30:00Z']],
            'schedule' => ['frequency' => 'daily', 'keep' => 5, 'databases' => [['engine' => 'mariadb', 'name' => 'app']]],
        ])->byDefault();
        $this->agent->shouldReceive('call')->with('job.status', Mockery::any())->andReturn(['status' => 'done', 'output' => []])->byDefault();
        $this->agent->shouldReceive('call')->with('sys.versions')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create());
        $this->site = Site::create(['domain' => 'app.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active', 'system_user' => 'web_app_example_com']);
        Database::create(['engine' => 'mariadb', 'name' => 'app', 'site_id' => $this->site->id]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->backupDir));
        parent::tearDown();
    }

    private function list(): Testable
    {
        return Livewire::test(BackupList::class, ['site' => $this->site->id]);
    }

    public function test_the_tab_lists_backups_and_the_schedule(): void
    {
        $this->get("/sites/{$this->site->id}/backups")->assertOk()->assertSee('Zálohy');

        $this->list()->assertSee(self::FILE)->assertSee('2,4 MB')
            ->assertSee('Automaticky každý den')->assertSee('uchovává se 5 posledních, včetně databází');
    }

    public function test_backup_now_includes_the_sites_databases(): void
    {
        $this->agent->shouldReceive('call')->once()
            ->with('site.backup', ['domain' => 'app.example.com', 'databases' => [['engine' => 'mariadb', 'name' => 'app']]])
            ->andReturn(['job_id' => 'j1']);

        $this->headerTableAction($this->list(), 'backupNow', ['databases' => true])->assertNotified('Záloha vytvořena');
    }

    public function test_restore_needs_the_domain_typed_and_reports_the_result(): void
    {
        $b = $this->tableAction($this->list(), self::FILE, 'restore', ['files' => true, 'databases' => true, 'confirm' => 'wrong.com']);
        $b->assertHasErrors();

        $this->agent->shouldReceive('call')->once()
            ->with('site.restore', ['domain' => 'app.example.com', 'backup' => self::FILE, 'files' => true, 'databases' => true, 'confirm' => 'app.example.com'])
            ->andReturn(['job_id' => 'j2']);
        $this->agent->shouldReceive('call')->with('job.status', ['id' => 'j2'])->andReturn(['status' => 'done', 'output' => [
            'release' => '20261107T100000Z', 'healthy' => true, 'databases' => ['app'], 'skipped_databases' => [],
            'snapshot' => 'app.example.com-20261107T100000Z-pre-restore.tar.gz',
        ]]);

        $this->tableAction($this->list(), self::FILE, 'restore', ['files' => true, 'databases' => true, 'confirm' => 'app.example.com'])
            ->assertHasNoErrors()->assertNotified('Záloha obnovena');
    }

    public function test_schedule_is_saved_with_the_databases(): void
    {
        $this->agent->shouldReceive('call')->once()
            ->with('site.backup_schedule', ['domain' => 'app.example.com', 'frequency' => 'weekly', 'keep' => 10, 'databases' => [['engine' => 'mariadb', 'name' => 'app']]])
            ->andReturn([]);

        $this->headerTableAction($this->list(), 'schedule', ['frequency' => 'weekly', 'keep' => 10, 'databases' => true])
            ->assertHasNoErrors()->assertNotified('Plán záloh uložen');

        $this->headerTableAction($this->list(), 'schedule', ['frequency' => 'hourly', 'keep' => 0])->assertHasErrors();
    }

    public function test_download_serves_only_this_sites_backups(): void
    {
        $dir = $this->backupDir.'/sites/app.example.com';
        mkdir($dir, 0o755, true);
        file_put_contents($dir.'/'.self::FILE, 'archive');

        $this->get(route('site-backups.download', [$this->site, self::FILE]))->assertOk()->assertDownload(self::FILE);
        $this->get("/sites/{$this->site->id}/backups/other.com-20261107T023000Z.tar.gz")->assertNotFound();
        $this->get("/sites/{$this->site->id}/backups/app.example.com-20261107T023000Z.tar.gz.x")->assertNotFound();
        $this->get("/sites/{$this->site->id}/backups/..%2F..%2Fetc%2Fshadow.tar.gz")->assertNotFound();
    }
}
