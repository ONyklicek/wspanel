<?php

namespace Tests\Feature;

use App\Actions\Databases\SyncDatabases;
use App\Filament\Resources\Databases\Pages\ListDatabases;
use App\Filament\Resources\Databases\Pages\ViewDatabase;
use App\Filament\Resources\Databases\RelationManagers\BackupsRelationManager;
use App\Models\Backup;
use App\Models\Database;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DatabasesTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $agent;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Mockery::mock(AgentClient::class);
        $this->agent->shouldReceive('call')->with('sys.versions')->andReturn(['db_engines' => ['mariadb', 'postgresql']])->byDefault();
        $this->agent->shouldReceive('call')->with('db.backups', Mockery::any())->andReturn(['backups' => [], 'schedule' => ['frequency' => 'off']])->byDefault();
        $this->app->instance(AgentClient::class, $this->agent);
        $this->backupDir = sys_get_temp_dir().'/wspanel-test-backups-'.uniqid();
        config(['agent.backup_dir' => $this->backupDir]);
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->backupDir));
        parent::tearDown();
    }

    public function test_database_for_a_site_writes_its_env_and_shows_no_password(): void
    {
        $site = Site::create(['domain' => 'shop.example.com', 'mode' => 'classic', 'framework' => 'laravel', 'php_version' => '8.4', 'status' => 'active']);
        $this->agent->shouldReceive('call')->once()
            ->with('db.create', ['engine' => 'mariadb', 'name' => 'shop_example_com', 'domain' => 'shop.example.com'])
            ->andReturn(['name' => 'shop_example_com', 'env_file' => '/srv/sites/shop.example.com/shared/.env']);

        Livewire::test(ListDatabases::class)
            ->callAction('createDatabase', ['engine' => 'mariadb', 'site_id' => $site->id, 'name' => 'shop_example_com'])
            ->assertHasNoActionErrors()
            ->assertNotified('Databáze shop_example_com vytvořena');

        $this->assertTrue(Database::sole()->site->is($site));
    }

    public function test_standalone_database_shows_the_password_once(): void
    {
        $this->agent->shouldReceive('call')->once()
            ->with('db.create', ['engine' => 'postgresql', 'name' => 'reports'])
            ->andReturn(['name' => 'reports', 'password' => 'S3cretGeneratedByAgent']);

        Livewire::test(ListDatabases::class)
            ->callAction('createDatabase', ['engine' => 'postgresql', 'site_id' => null, 'name' => 'reports'])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('databases', ['engine' => 'postgresql', 'name' => 'reports', 'site_id' => null]);
        Notification::assertNotified(
            Notification::make()
                ->title('Databáze reports vytvořena')
                ->body('Uživatel reports, heslo: S3cretGeneratedByAgent (zobrazeno jen teď)')
                ->success()
                ->persistent(),
        );
    }

    #[TestWith(['Shop'])]
    #[TestWith(['1shop'])]
    #[TestWith(['shop-db'])]
    #[TestWith(['mysql'])]
    #[TestWith(["x'; DROP DATABASE y; --"])]
    public function test_invalid_names_never_reach_the_agent(string $name): void
    {
        $this->agent->shouldNotReceive('call')->with('db.create', Mockery::any());

        Livewire::test(ListDatabases::class)
            ->callAction('createDatabase', ['engine' => 'mariadb', 'site_id' => null, 'name' => $name])
            ->assertHasActionErrors(['name']);
    }

    public function test_sync_mirrors_real_databases_and_backups(): void
    {
        $gone = Database::create(['engine' => 'mariadb', 'name' => 'dropped_elsewhere']);
        $this->agent->shouldReceive('call')->with('db.list', ['engine' => 'mariadb'])->andReturn([['name' => 'shop', 'size' => 2048]]);
        $this->agent->shouldReceive('call')->with('db.list', ['engine' => 'postgresql'])->andReturn([]);
        $this->agent->shouldReceive('call')->with('db.backups', ['engine' => 'mariadb', 'name' => 'shop'])->andReturn([
            'backups' => [['file' => 'shop-20261107T030000Z.sql.gz', 'size' => 512, 'created' => '2026-11-07T03:00:00Z']],
            'schedule' => ['frequency' => 'daily', 'keep' => 14],
        ]);

        app(SyncDatabases::class)->handle();

        $this->assertModelMissing($gone);
        $shop = Database::sole();
        $this->assertSame(2048, $shop->size);
        $this->assertSame('daily', $shop->backup_frequency);
        $this->assertSame(14, $shop->backup_keep);
        $this->assertSame('shop-20261107T030000Z.sql.gz', $shop->backups()->sole()->file);
    }

    public function test_restore_requires_typing_the_name_and_runs_as_a_job(): void
    {
        $db = Database::create(['engine' => 'mariadb', 'name' => 'shop']);
        $backup = Backup::create(['database_id' => $db->id, 'file' => 'shop-20261107T030000Z.sql.gz', 'size' => 1, 'taken_at' => now()]);

        Livewire::test(BackupsRelationManager::class, ['ownerRecord' => $db, 'pageClass' => ViewDatabase::class])
            ->callTableAction('restore', $backup, data: ['confirm' => 'other'])
            ->assertHasTableActionErrors(['confirm']);

        $this->agent->shouldReceive('call')->once()
            ->with('db.restore', ['engine' => 'mariadb', 'name' => 'shop', 'backup' => 'shop-20261107T030000Z.sql.gz', 'confirm' => 'shop'])
            ->andReturn(['job_id' => 'abcdef0123456789']);
        $this->agent->shouldReceive('call')->once()->with('job.status', ['id' => 'abcdef0123456789'])
            ->andReturn(['status' => 'done', 'output' => ['snapshot' => 'shop-20261107T040000Z-pre-restore.sql.gz']]);

        Livewire::test(BackupsRelationManager::class, ['ownerRecord' => $db, 'pageClass' => ViewDatabase::class])
            ->callTableAction('restore', $backup, data: ['confirm' => 'shop'])
            ->assertNotified('Databáze obnovena');
    }

    public function test_guests_cannot_download_backups(): void
    {
        $backup = $this->backupFile('shop', 'shop-20261107T030000Z.sql.gz', 'dump-data');
        auth()->logout();

        $this->get(route('filament.admin.backups.download', $backup))->assertRedirect('/login');
    }

    public function test_admin_downloads_a_backup(): void
    {
        $backup = $this->backupFile('shop', 'shop-20261107T030000Z.sql.gz', 'dump-data');

        $this->get(route('filament.admin.backups.download', $backup))
            ->assertOk()
            ->assertDownload('shop-20261107T030000Z.sql.gz');
    }

    public function test_tampered_backup_records_are_not_served(): void
    {
        $other = $this->backupFile('other', 'other-20261107T030000Z.sql.gz', 'secret');
        $shop = Database::create(['engine' => 'mariadb', 'name' => 'shop']);
        $foreign = Backup::create(['database_id' => $shop->id, 'file' => 'other-20261107T030000Z.sql.gz', 'size' => 1, 'taken_at' => now()]);
        $traversal = Backup::create(['database_id' => $shop->id, 'file' => '../other/other-20261107T030000Z.sql.gz', 'size' => 1, 'taken_at' => now()]);

        $this->get(route('filament.admin.backups.download', $foreign))->assertNotFound();
        $this->get(route('filament.admin.backups.download', $traversal))->assertNotFound();
        $this->assertNotNull($other->path());
    }

    public function test_symlinked_backup_files_are_not_served(): void
    {
        $db = Database::create(['engine' => 'mariadb', 'name' => 'shop']);
        @mkdir($this->backupDir.'/mariadb/shop', 0777, true);
        symlink('/etc/hosts', $this->backupDir.'/mariadb/shop/shop-20261107T030000Z.sql.gz');
        $backup = Backup::create(['database_id' => $db->id, 'file' => 'shop-20261107T030000Z.sql.gz', 'size' => 1, 'taken_at' => now()]);

        $this->get(route('filament.admin.backups.download', $backup))->assertNotFound();
    }

    public function test_opening_a_tool_posts_a_one_time_token(): void
    {
        $db = Database::create(['engine' => 'mariadb', 'name' => 'shop']);
        $this->agent->shouldReceive('call')->once()
            ->with('db.access', ['engine' => 'mariadb', 'name' => 'shop', 'tool' => 'phpmyadmin'])
            ->andReturn(['token' => str_repeat('ab', 32), 'signon_path' => '/dbtools/pma/wspanel-signon.php']);

        $this->get(route('filament.admin.dbtools.open', [$db, 'phpmyadmin']))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('action="/dbtools/pma/wspanel-signon.php"', false)
            ->assertSee('name="token" value="'.str_repeat('ab', 32).'"', false)
            ->assertSee('method="post"', false);
    }

    public function test_unknown_tools_and_guests_are_refused(): void
    {
        $db = Database::create(['engine' => 'mariadb', 'name' => 'shop']);
        $this->agent->shouldNotReceive('call')->with('db.access', Mockery::any());

        $this->get('/db-open/'.$db->id.'/sqlite')->assertNotFound();
        auth()->logout();
        $this->get(route('filament.admin.dbtools.open', [$db, 'adminer']))->assertRedirect('/login');
    }

    public function test_installing_a_missing_engine(): void
    {
        $this->agent->shouldReceive('call')->with('sys.versions')->andReturn(['db_engines' => ['mariadb']]);
        $this->agent->shouldReceive('call')->once()->with('engine.install', ['engine' => 'postgresql'])->andReturn(['job_id' => 'abcdef0123456789']);
        $this->agent->shouldReceive('call')->once()->with('job.status', ['id' => 'abcdef0123456789'])->andReturn(['status' => 'done', 'output' => []]);

        Livewire::test(ListDatabases::class)
            ->assertActionVisible('installEngine')
            ->callAction('installEngine', ['engine' => 'postgresql'])
            ->assertHasNoActionErrors()
            ->assertNotified('Engine nainstalován');

        Livewire::test(ListDatabases::class)
            ->callAction('installEngine', ['engine' => 'mariadb'])
            ->assertHasActionErrors(['engine']);
    }

    private function backupFile(string $dbName, string $file, string $content): Backup
    {
        $db = Database::firstOrCreate(['engine' => 'mariadb', 'name' => $dbName]);
        @mkdir($this->backupDir.'/mariadb/'.$dbName, 0777, true);
        file_put_contents($this->backupDir.'/mariadb/'.$dbName.'/'.$file, $content);

        return Backup::create(['database_id' => $db->id, 'file' => $file, 'size' => strlen($content), 'taken_at' => now()]);
    }
}
