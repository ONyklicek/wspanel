<?php

namespace App\Livewire\Games;

use App\Actions\Games\ManageGameServers;
use App\Livewire\Concerns\TranslatesPageText;
use App\Services\Agent\AgentException;
use App\Support\Limits;
use App\Support\Toast;
use App\Wire\Resources\GameServerResource;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListGameServers extends ListPage
{
    use TranslatesPageText;

    protected static ?string $resource = GameServerResource::class;

    protected ?string $title = 'Herní servery';

    protected function headerActions(): array
    {
        return [
            Action::make('create')->label(__('Nový server'))->icon('outline:plus')
                ->visible(fn () => app(Limits::class)->canAdd(Limits::forCurrentUser(), 'games'))
                ->modalDescription(__('Server se stáhne od vydavatele hry (s kontrolou otisku) a spustí; Minecraft si napoprvé vygeneruje svět, to trvá minutu až dvě.'))
                ->form(fn () => GameServerResource::createFields())
                ->action(function (array $data) {
                    try {
                        // Installs in the background: the server's page shows how it goes.
                        $server = app(ManageGameServers::class)->create($data);
                        $this->redirect(GameServerResource::url('view', $server));
                    } catch (AgentException $e) {
                        Toast::error(__('Server se nepodařilo vytvořit'), $e->getMessage());
                    }
                }),
        ];
    }
}
