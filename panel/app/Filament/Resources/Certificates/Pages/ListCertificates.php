<?php

namespace App\Filament\Resources\Certificates\Pages;

use App\Actions\SyncCertificatesAndTasks;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Services\Agent\AgentException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListCertificates extends ListRecords
{
    protected static string $resource = CertificateResource::class;

    protected ?string $subheading = 'Certifikáty vydává a obnovuje proxy sama (Let\'s Encrypt), jakmile doména míří na server a port 443 je otevřený.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Ověřit stav')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function () {
                    try {
                        $n = app(SyncCertificatesAndTasks::class)->certificates();
                        Notification::make()->title("Ověřeno {$n} domén")->success()->send();
                    } catch (AgentException $e) {
                        Notification::make()->title('Agent není dostupný')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
