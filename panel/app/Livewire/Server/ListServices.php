<?php

namespace App\Livewire\Server;

use App\Actions\Services\SyncServices;
use App\Livewire\Concerns\TranslatesPageText;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Resources\ServiceResource;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListServices extends ListPage
{
    use TranslatesPageText;

    protected static ?string $resource = ServiceResource::class;

    protected ?string $title = 'Služby';

    protected function headerActions(): array
    {
        return [
            Action::make('sync')
                ->label(__('Ověřit stav'))
                ->icon('outline:arrow-path')
                ->color('gray')
                ->action(function () {
                    try {
                        Toast::success(__('Stav ').app(SyncServices::class)->handle().__(' služeb ověřen'));
                    } catch (AgentException $e) {
                        Toast::error(__('Agent není dostupný'), $e->getMessage());
                    }
                }),
        ];
    }
}
