<?php

namespace App\Livewire\Pages;

use App\Actions\System\ManagePackages;
use App\Actions\System\ManageSystem;
use App\Livewire\Concerns\TranslatesPageText;
use App\Livewire\Concerns\WatchesBackgroundJobs;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Actions\Action;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WirePanels\Pages\Page;

/** The server itself: name, time, swap, network and disk health. */
class SystemPage extends Page
{
    use TranslatesPageText;
    use WatchesBackgroundJobs;

    protected static string $view = 'livewire.pages.system';

    protected static ?string $permission = 'admin';

    protected ?string $title = 'Systém';

    protected static ?string $slug = 'system';

    protected static ?string $navigationLabel = 'Systém';

    protected static ?string $navigationIcon = 'outline:cpu-chip';

    protected static ?string $navigationGroup = 'server';

    protected static int $navigationSort = 30;

    /** @var array<string, mixed>|null */
    public ?array $settings = null;

    /** @var array<string, mixed>|null */
    public ?array $network = null;

    /** @var array<string, mixed>|null */
    public ?array $disks = null;

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
        $system = app(ManageSystem::class);
        try {
            $this->settings = $system->settings();
            $this->network = $system->network();
            $this->disks = $system->disks();
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function toggleNtp(): void
    {
        $on = ! ($this->settings['ntp'] ?? false);
        try {
            app(ManageSystem::class)->setNtp($on);
            Toast::success($on ? __('Synchronizace času zapnuta') : __('Synchronizace času vypnuta'));
        } catch (AgentException $e) {
            Toast::error(__('Změna selhala'), $e->getMessage());
        }
        $this->load();
    }

    public function installSmart(): void
    {
        try {
            app(ManagePackages::class)->install('smartmontools');
        } catch (AgentException $e) {
            Toast::error(__('Instalace selhala'), $e->getMessage());
        }
        $this->load();
    }

    protected function headerActions(): array
    {
        $run = fn (callable $call, string $ok) => function (array $data) use ($call, $ok) {
            try {
                $call($data);
                Toast::success(__($ok));
            } catch (AgentException $e) {
                Toast::error(__('Změna selhala'), $e->getMessage());
            }
            $this->load();
        };

        return [
            Action::make('hostname')->label(__('Název serveru'))->icon('outline:pencil-square')->color('gray')
                ->modalDescription(__('Název, pod kterým se server hlásí (hostname), např. web1.example.com. Adresu panelu ani název pro poštu nemění.'))
                ->fillFormUsing(fn () => ['hostname' => $this->settings['hostname'] ?? ''])
                ->form([
                    TextInput::make('hostname')->label(__('Název'))->required()->maxLength(253)
                        ->rules(['regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/']),
                ])
                ->action($run(fn ($d) => app(ManageSystem::class)->setHostname((string) $d['hostname']), 'Název serveru změněn')),
            Action::make('timezone')->label(__('Časové pásmo'))->icon('outline:globe-europe-africa')->color('gray')
                ->modalDescription(__('Podle časového pásma serveru běží naplánované úlohy, automatický restart a časy v logech.'))
                ->fillFormUsing(fn () => ['timezone' => $this->settings['timezone'] ?? 'UTC'])
                ->form(fn () => [
                    Select::make('timezone')->label(__('Časové pásmo'))->searchable()->required()
                        ->options(fn () => collect($this->timezones())->mapWithKeys(fn ($z) => [$z => $z])->all())
                        ->rules([fn () => 'in:'.implode(',', $this->timezones())]),
                ])
                ->action($run(fn ($d) => app(ManageSystem::class)->setTimezone((string) $d['timezone']), 'Časové pásmo změněno')),
            Action::make('swap')->label(__('Swap'))->icon('outline:arrows-right-left')->color('gray')
                ->modalDescription(__('Swapový soubor na disku: když dojde paměť, jádro do něj odloží málo používané stránky místo ukončování procesů. Velikost 0 swapový soubor odstraní. Swappiness (0–200) říká, jak ochotně jádro swapuje; pro servery se hodí 10.'))
                ->fillFormUsing(fn () => [
                    'size_mb' => (int) round(($this->settings['swap_file_size'] ?? 0) / 1048576),
                    'swappiness' => max(0, (int) ($this->settings['swappiness'] ?? 60)),
                ])
                ->form([
                    TextInput::make('size_mb')->label(__('Velikost swapového souboru (MB)'))->integer()->minValue(0)->maxValue(65536)
                        ->rules(['required', 'integer', function (string $attr, mixed $value, \Closure $fail) {
                            if ((int) $value !== 0 && ((int) $value < 256 || (int) $value > 65536)) {
                                $fail(__('Zadejte 0 (bez swapu) nebo 256 až 65536 MB.'));
                            }
                        }]),
                    TextInput::make('swappiness')->label(__('Swappiness'))->integer()->minValue(0)->maxValue(200)->rules(['required', 'integer', 'between:0,200']),
                ])
                ->action($run(function ($d) {
                    $current = (int) round(($this->settings['swap_file_size'] ?? 0) / 1048576);
                    app(ManageSystem::class)->setSwap((int) $d['size_mb'] === $current ? null : (int) $d['size_mb'], (int) $d['swappiness']);
                }, 'Swap nastaven')),
        ];
    }

    /** @return list<string> */
    private function timezones(): array
    {
        static $zones = null;
        try {
            return $zones ??= app(ManageSystem::class)->settings(true)['timezones'] ?? [];
        } catch (AgentException) {
            return [];
        }
    }
}
