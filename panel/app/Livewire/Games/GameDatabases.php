<?php

namespace App\Livewire\Games;

use App\Actions\Games\ManageGameDatabases;
use App\Livewire\Games\Concerns\IsGameTab;
use App\Services\Agent\AgentException;
use App\Support\PageActions;
use App\Support\Toast;
use App\Wire\Actions\Action;
use App\Wire\Resources\GameServerResource;
use Illuminate\Contracts\View\View;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

/**
 * The databases a game server uses, shared with a site when wanted: a
 * plugin (LuckPerms, CoreProtect, a shop) and the site read the same data,
 * each with a login of its own.
 */
class GameDatabases extends ViewPage
{
    use IsGameTab;

    protected static ?string $resource = GameServerResource::class;

    /** @var list<array<string, mixed>> */
    public array $links = [];

    public ?string $error = null;

    public function mount(mixed $record = null): void
    {
        parent::mount($record);
        $this->load();
    }

    protected function tabLabel(): string
    {
        return __('Databáze');
    }

    public function load(): void
    {
        try {
            $this->links = app(ManageGameDatabases::class)->links($this->server());
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    protected function headerActions(): array
    {
        $dbs = fn () => app(ManageGameDatabases::class);
        $prefix = TextInput::make('prefix')->label(__('Předpona v .env webu'))->default('GAME_DB')->maxLength(31)
            ->rules(['nullable', 'regex:/^[A-Z][A-Z0-9_]{0,30}$/', 'not_in:DB'])
            ->helperText(__('Web dostane :p_HOST, :p_PORT, :p_DATABASE, :p_USERNAME a :p_PASSWORD; jeho vlastní DB_* zůstanou.', ['p' => 'GAME_DB']));

        return PageActions::arrange([
            Action::make('attach')->label(__('Připojit databázi'))->icon('outline:link')
                ->modalDescription(__('Databáze stejného vlastníka na sdíleném serveru MariaDB. Herní server dostane vlastní přihlášení jen k ní.'))
                ->form(fn () => [
                    Select::make('name')->label(__('Databáze'))->required()->searchable()->options($dbs()->attachable($this->server()))
                        ->rules([fn () => 'in:'.implode(',', array_keys($dbs()->attachable($this->server())))]),
                ])
                ->action(function (array $data) use ($dbs) {
                    $this->run(fn () => $dbs()->attach($this->server(), (string) $data['name']), __('Databáze připojena'));
                }),
            Action::make('create')->label(__('Nová databáze'))->icon('outline:plus')->color('gray')
                ->modalDescription(__('Nová databáze MariaDB vlastníka herního serveru (počítá se do jeho balíčku). Rovnou ji můžete sdílet s webem.'))
                ->form(fn () => [
                    TextInput::make('name')->label(__('Název'))->required()->maxLength(40)->rules(['regex:/^[a-z][a-z0-9_]{0,39}$/'])->placeholder('minecraft'),
                    Select::make('domain')->label(__('Sdílet s webem'))->placeholder(__('nesdílet'))->options($dbs()->sites($this->server()))->live(),
                    (clone $prefix)->visible(fn ($get) => filled($get('domain'))),
                ])
                ->action(function (array $data) use ($dbs) {
                    $this->run(fn () => $dbs()->create($this->server(), (string) $data['name'], $data['domain'] ?? null, (string) ($data['prefix'] ?? '') ?: 'GAME_DB'), __('Databáze vytvořena'));
                }),
            Action::make('share')->label(__('Sdílet s webem'))->icon('outline:share')->color('gray')
                ->visible(fn () => $this->links !== [])
                ->modalDescription(__('Web dostane do .env vlastní přihlášení k databázi pod zvolenou předponou.'))
                ->form(fn () => [
                    Select::make('name')->label(__('Databáze'))->required()->options(collect($this->links)->pluck('name', 'name')->all()),
                    Select::make('domain')->label(__('Web'))->required()->searchable()->options($dbs()->sites($this->server())),
                    $prefix,
                ])
                ->action(function (array $data) use ($dbs) {
                    $this->run(fn () => $dbs()->share($this->server(), (string) $data['name'], (string) $data['domain'], (string) ($data['prefix'] ?? '') ?: 'GAME_DB'), __('Databáze sdílena s webem'));
                }),
        ]);
    }

    public function newPassword(string $name): void
    {
        $this->run(fn () => app(ManageGameDatabases::class)->newPassword($this->server(), $name), __('Nové heslo nastaveno'), __('Zadejte ho i do nastavení pluginů.'));
    }

    public function detach(string $name): void
    {
        $this->run(fn () => app(ManageGameDatabases::class)->detach($this->server(), $name), __('Databáze odpojena'), __('Databáze i její data zůstaly; herní server k ní už nemá přístup.'));
    }

    public function unshare(string $name, string $domain): void
    {
        $this->run(fn () => app(ManageGameDatabases::class)->unshare($this->server(), $name, $domain), __('Sdílení s webem zrušeno'), __('Údaje v .env webu zůstaly, ale už neplatí.'));
    }

    private function run(callable $change, string $done, ?string $body = null): void
    {
        try {
            $change();
            Toast::success($done, $body);
        } catch (AgentException $e) {
            Toast::error(__('Změna se nepodařila'), $e->getMessage());
        }
        $this->load();
    }

    public function render(): View
    {
        return $this->tabView('livewire.games.databases');
    }
}
