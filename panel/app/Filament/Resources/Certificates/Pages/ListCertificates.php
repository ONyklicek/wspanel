<?php

namespace App\Filament\Resources\Certificates\Pages;

use App\Actions\Sites\ManageTls;
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

    protected ?string $subheading = 'Veřejné domény: Let\'s Encrypt, vydává a obnovuje se samo. Domény ve vnitřní síti: interní certifikační autorita serveru. Nebo vlastní certifikát (web → Další → HTTPS / certifikát).';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rootCa')
                ->label('Kořenový certifikát (interní CA)')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('gray')
                ->modalHeading('Kořenový certifikát interní certifikační autority')
                ->modalWidth('3xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Zavřít')
                ->modalContent(function () {
                    try {
                        $ca = app(ManageTls::class)->rootCa();
                    } catch (AgentException $e) {
                        $ca = null;
                        $error = $e->getMessage();
                    }

                    return view('filament.resources.certificates.root-ca', ['ca' => $ca, 'error' => $error ?? null]);
                }),
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
