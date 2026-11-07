<?php

namespace App\Filament\Resources\Services\Pages;

use App\Actions\Services\SyncServices;
use App\Filament\Resources\Services\ServiceResource;
use App\Services\Agent\AgentException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListServices extends ListRecords
{
    protected static string $resource = ServiceResource::class;

    protected ?string $subheading = 'Skutečný stav ze systemd. Panel ho ověřuje každou minutu.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Ověřit stav')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function () {
                    try {
                        $n = app(SyncServices::class)->handle();
                        Notification::make()->title("Stav {$n} služeb ověřen")->success()->send();
                    } catch (AgentException $e) {
                        Notification::make()->title('Agent není dostupný')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
