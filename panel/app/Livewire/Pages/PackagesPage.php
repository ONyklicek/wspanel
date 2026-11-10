<?php

namespace App\Livewire\Pages;

use App\Actions\System\ManagePackages;
use App\Livewire\Concerns\TranslatesPageText;
use App\Livewire\Concerns\WatchesBackgroundJobs;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use Illuminate\Support\Str;
use NyonCode\WirePanels\Pages\Page;

/** Debian packages: search and install, hold, remove what was added. */
class PackagesPage extends Page
{
    use TranslatesPageText;
    use WatchesBackgroundJobs;

    protected static string $view = 'livewire.pages.packages';

    protected static ?string $permission = 'admin';

    protected ?string $title = 'Balíčky';

    protected static ?string $slug = 'system-packages';

    protected static ?string $navigationLabel = 'Balíčky';

    protected static ?string $navigationIcon = 'outline:cube';

    protected static ?string $navigationGroup = 'server';

    protected static int $navigationSort = 35;

    public string $query = '';

    /** @var list<array<string, mixed>>|null */
    public ?array $found = null;

    public int $foundTotal = 0;

    /** @var list<array<string, mixed>> */
    public array $installed = [];

    public string $filter = '';

    public bool $manualOnly = true;

    /**
     * An install or removal waiting for confirmation, with what apt would do.
     *
     * @var array{action: string, name: string, install: list<string>, remove: list<string>, allowed: bool, reason?: string}|null
     */
    public ?array $pending = null;

    public ?string $error = null;

    protected function backgroundJobKeys(): array
    {
        return ['packages'];
    }

    /** A job of this page ended: the page as it is now. */
    protected function backgroundJobFinished(string $key, bool $ok): void
    {
        $this->load();
    }

    public function mount(): void
    {
        $this->load();
    }

    public function load(): void
    {
        try {
            $this->installed = app(ManagePackages::class)->installed();
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function findPackages(): void
    {
        $this->validate(['query' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9.+-]*$/']], [], ['query' => __('název balíčku')]);
        try {
            $out = app(ManagePackages::class)->search($this->query);
            [$this->found, $this->foundTotal] = [$out['packages'], $out['total']];
        } catch (AgentException $e) {
            Toast::error(__('Hledání selhalo'), $e->getMessage());
        }
    }

    /** @return list<array<string, mixed>> */
    public function shownInstalled(): array
    {
        $term = Str::lower(trim($this->filter));

        return collect($this->installed)
            ->filter(fn ($p) => (! $this->manualOnly || $p['manual'] || $p['held']) && ($term === '' || Str::contains(Str::lower($p['name'].' '.$p['summary']), $term)))
            ->values()->all();
    }

    public function ask(string $action, string $name): void
    {
        if (! in_array($action, ['install', 'remove'], true)) {
            return;
        }
        try {
            $this->pending = ['action' => $action, 'name' => $name] + app(ManagePackages::class)->preview($name, $action);
        } catch (AgentException $e) {
            Toast::error($action === 'install' ? __('Balíček nejde nainstalovat') : __('Balíček nejde odebrat'), $e->getMessage());
        }
    }

    public function cancelPending(): void
    {
        $this->pending = null;
    }

    public function confirmPending(): void
    {
        if ($this->pending === null || ! $this->pending['allowed']) {
            return;
        }
        ['action' => $action, 'name' => $name] = $this->pending;
        $this->pending = null;
        try {
            $action === 'install' ? app(ManagePackages::class)->install($name) : app(ManagePackages::class)->remove($name);
        } catch (AgentException $e) {
            Toast::error($action === 'install' ? __('Instalace selhala') : __('Odebrání selhalo'), $e->getMessage());
        }
        $this->loadBackgroundJobs();
        $this->load();
        if ($this->found !== null) {
            $this->findPackages();
        }
    }

    public function hold(string $name, bool $hold): void
    {
        try {
            app(ManagePackages::class)->hold($name, $hold);
            Toast::success($hold ? __(':name zůstane ve své verzi', ['name' => $name]) : __(':name se zase aktualizuje', ['name' => $name]));
        } catch (AgentException $e) {
            Toast::error(__('Změna selhala'), $e->getMessage());
        }
        $this->load();
    }
}
