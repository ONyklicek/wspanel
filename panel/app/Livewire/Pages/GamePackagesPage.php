<?php

namespace App\Livewire\Pages;

use App\Actions\Games\ManageGamePackages;
use App\Livewire\Concerns\TranslatesPageText;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Actions\Action;
use NyonCode\WireForms\Components\Textarea;
use NyonCode\WirePanels\Pages\Page;

/**
 * Server → Herní balíčky: which games and variants game servers can run.
 * Built-in packages (Minecraft, Factorio), WSPanel's signed catalog (Folia…)
 * and the administrator's own manifests; the agent checks each.
 */
class GamePackagesPage extends Page
{
    use TranslatesPageText;

    protected static string $view = 'livewire.pages.game-packages';

    protected static ?string $permission = 'game-packages';

    protected ?string $title = 'Herní balíčky';

    protected static ?string $slug = 'game-packages';

    protected static ?string $navigationLabel = 'Herní balíčky';

    protected static ?string $navigationIcon = 'outline:puzzle-piece';

    protected static ?string $navigationGroup = 'server';

    protected static int $navigationSort = 36;

    /** @var list<array<string, mixed>> */
    public array $packages = [];

    /** @var list<array<string, mixed>> */
    public array $problems = [];

    /** @var list<array<string, mixed>>|null null until the catalog is asked */
    public ?array $catalog = null;

    public ?string $catalogError = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->load();
    }

    /** What is installed, and (the network: on the page's first paint) the catalog. */
    public function load(bool $catalog = false): void
    {
        try {
            $out = app(ManageGamePackages::class)->list($catalog || $this->catalog !== null);
            $this->packages = $out['packages'] ?? [];
            $this->problems = $out['problems'] ?? [];
            if (array_key_exists('catalog', $out)) {
                $this->catalog = $out['catalog'];
                $this->catalogError = $out['catalog_error'] ?? null;
            }
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function loadCatalog(): void
    {
        $this->load(catalog: true);
    }

    public function install(string $id): void
    {
        $this->run(fn () => app(ManageGamePackages::class)->install($id), __('Balíček :id nainstalován', ['id' => $id]),
            __('Jeho hry a varianty nabídne formulář nového herního serveru.'));
    }

    public function remove(string $id): void
    {
        $this->run(fn () => app(ManageGamePackages::class)->remove($id), __('Balíček :id odebrán', ['id' => $id]));
    }

    private function run(callable $op, string $done, ?string $body = null): void
    {
        abort_unless(auth()->user()?->can('game-packages'), 403);
        try {
            $op();
            Toast::success($done, $body);
        } catch (AgentException $e) {
            Toast::error(__('Nepovedlo se'), $e->getMessage());
        }
        $this->load();
    }

    protected function headerActions(): array
    {
        return [
            Action::make('upload')->label(__('Nahrát vlastní balíček'))->icon('outline:arrow-up-tray')
                ->modalDescription(__('Manifest herního balíčku (JSON): přidá hru, nebo rozšíří existující o varianty. Nic se z něj nespouští; agent ho celý zkontroluje a uloží do /etc/wspanel/games.d. Formát popisuje games/README.md v repozitáři WSPanelu.'))
                ->modalWidth('3xl')
                ->modalSubmitActionLabel(__('Nahrát'))
                ->form([
                    Textarea::make('manifest')->label(__('Manifest (JSON)'))->rows(14)->required()
                        ->rules(['required', 'string', 'max:65536', 'json'])
                        ->placeholder("{\n  \"id\": \"minecraft-purpur\",\n  \"name\": \"Purpur pro Minecraft\",\n  \"version\": \"1.0.0\",\n  \"extends\": \"minecraft\",\n  \"flavors\": [ … ]\n}"),
                ])
                ->action(function (array $data) {
                    abort_unless(auth()->user()?->can('game-packages'), 403);
                    try {
                        $out = app(ManageGamePackages::class)->upload((string) $data['manifest']);
                        Toast::success(__('Balíček :name nahrán', ['name' => $out['installed']['name'] ?? '']), __('Jeho hry a varianty nabídne formulář nového herního serveru.'));
                    } catch (AgentException $e) {
                        Toast::error(__('Balíček nebyl přijat'), $e->getMessage());
                    }
                    $this->load();
                }),
        ];
    }
}
