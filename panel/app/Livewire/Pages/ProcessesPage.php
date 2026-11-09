<?php

namespace App\Livewire\Pages;

use App\Actions\System\ManageSystem;
use App\Livewire\Concerns\TranslatesPageText;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use Illuminate\Support\Str;
use NyonCode\WirePanels\Pages\Page;

/** Every process on the server, ending one, and what takes the disk. */
class ProcessesPage extends Page
{
    use TranslatesPageText;

    protected static string $view = 'livewire.pages.processes';

    protected static ?string $permission = 'admin';

    protected ?string $title = 'Procesy a disk';

    protected static ?string $slug = 'processes';

    protected static ?string $navigationLabel = 'Procesy';

    protected static ?string $navigationIcon = 'outline:queue-list';

    protected static ?string $navigationGroup = 'server';

    protected static int $navigationSort = 12;

    /** @var list<array<string, mixed>> */
    public array $processes = [];

    public string $search = '';

    /** cpu | rss */
    public string $sort = 'cpu';

    public string $path = '/';

    /** @var array<string, mixed>|null */
    public ?array $usage = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->load();
    }

    public function load(): void
    {
        try {
            $this->processes = app(ManageSystem::class)->processes();
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function sortBy(string $by): void
    {
        $this->sort = $by === 'rss' ? 'rss' : 'cpu';
    }

    /** @return list<array<string, mixed>> */
    public function shown(): array
    {
        $term = Str::lower(trim($this->search));

        return collect($this->processes)
            ->filter(fn ($p) => $term === '' || Str::contains(Str::lower($p['user'].' '.$p['cmd'].' '.$p['name'].' '.($p['site'] ?? '').' '.$p['pid']), $term))
            ->sortByDesc($this->sort)
            ->take(300)
            ->values()
            ->all();
    }

    public function terminate(int $pid, int $start, bool $force = false): void
    {
        try {
            app(ManageSystem::class)->kill($pid, $start, $force);
            Toast::success($force ? __('Proces :pid ukončen natvrdo', ['pid' => $pid]) : __('Procesu :pid poslán pokyn k ukončení', ['pid' => $pid]));
        } catch (AgentException $e) {
            Toast::error(__('Proces se nepodařilo ukončit'), $e->getMessage());
        }
        usleep(300_000);
        $this->load();
    }

    public function browse(?string $path = null): void
    {
        $this->path = $path ?? $this->path;
        try {
            $this->usage = app(ManageSystem::class)->diskUsage($this->path);
        } catch (AgentException $e) {
            $this->usage = null;
            Toast::error(__('Velikost se nepodařilo zjistit'), $e->getMessage());
        }
    }

    public function up(): void
    {
        $this->browse($this->path === '/' ? '/' : (dirname($this->path) ?: '/'));
    }
}
