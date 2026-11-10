<?php

namespace App\Livewire\Sites;

use App\Actions\Sites\RunSiteCommands;
use App\Livewire\Sites\Concerns\IsSiteTab;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\ConsoleText;
use App\Support\Toast;
use App\Wire\Resources\SiteResource;
use Illuminate\Contracts\View\View;
use NyonCode\WireCore\Infolists\Infolist;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

/** Quick commands and a command line as the site user (a tab of the site). */
class SiteConsole extends ViewPage
{
    use IsSiteTab;

    protected static ?string $resource = SiteResource::class;

    /** @var list<array{id: string, label: string}> */
    public array $commands = [];

    /** @var list<array<string, mixed>> */
    public array $runs = [];

    public string $line = '';

    /** The run shown and followed. */
    public ?string $run = null;

    public int $offset = 0;

    public bool $done = true;

    public ?int $exit = null;

    public ?string $error = null;

    /** The first part of a run's output, drawn when its box appears (later parts arrive as events). */
    protected string $initialOutput = '';

    public function mount(mixed $record = null): void
    {
        parent::mount($record);
        $this->load();
    }

    protected function tabLabel(): string
    {
        return __('Konzole');
    }

    public function site(): Site
    {
        /** @var Site */
        return $this->nativeRecord();
    }

    public function load(): void
    {
        try {
            $out = app(RunSiteCommands::class)->overview($this->site());
            [$this->commands, $this->runs] = [$out['commands'] ?? [], $out['runs'] ?? []];
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function mayRunCustom(): bool
    {
        return app(RunSiteCommands::class)->mayRunCustom($this->site());
    }

    public function quick(string $id): void
    {
        if (! in_array($id, array_column($this->commands, 'id'), true)) {
            return;
        }
        $this->start(fn () => app(RunSiteCommands::class)->run($this->site(), $id));
    }

    public function custom(): void
    {
        $this->validate(['line' => ['required', 'string', 'max:4000']], [], ['line' => __('příkaz')]);
        $this->start(fn () => app(RunSiteCommands::class)->runCustom($this->site(), $this->line));
    }

    private function start(callable $fn): void
    {
        if (! $this->done) {
            Toast::warning(__('Jiný příkaz ještě běží'));

            return;
        }
        try {
            [$this->run, $this->offset, $this->done, $this->exit] = [$fn(), 0, false, null];
            $this->follow(reset: true);
        } catch (AgentException $e) {
            Toast::error(__('Příkaz se nepodařilo spustit'), $e->getMessage());
        }
    }

    /**
     * Polled while the run is not done: only the new output goes to the
     * browser, which appends it (the output never rides the component state).
     */
    public function follow(bool $reset = false): void
    {
        if ($this->run === null) {
            return;
        }
        try {
            $out = app(RunSiteCommands::class)->output($this->site(), $this->run, $this->offset);
            $chunk = ConsoleText::tidy((string) $out['output']);
            if ($reset) {
                $this->initialOutput = $chunk;
            }
            if ($reset || $chunk !== '') {
                $this->dispatch('console-output', content: $chunk, reset: $reset);
            }
            [$this->offset, $this->done, $this->exit] = [(int) $out['offset'], (bool) $out['done'], $out['exit']];
            if ($this->done) {
                $this->load();
            }
        } catch (AgentException $e) {
            [$this->done, $this->error] = [true, $e->getMessage()];
        }
    }

    public function show(string $run): void
    {
        if (! preg_match('/^[0-9a-f]{16}$/', $run)) {
            return;
        }
        [$this->run, $this->offset, $this->done, $this->exit] = [$run, 0, false, null];
        $this->follow(reset: true);
    }

    public function infolist(): Infolist
    {
        return Infolist::make()->schema([]);
    }

    public function render(): View
    {
        return view('livewire.sites.console', [
            'title' => $this->getTitle(),
            'breadcrumbs' => $this->breadcrumbs(),
            'headerActions' => $this->renderedHeaderActions(),
            'subNavigation' => $this->subNavigation($this->nativeRecord()),
            'site' => $this->site(),
            'initialOutput' => $this->initialOutput,
        ]);
    }
}
