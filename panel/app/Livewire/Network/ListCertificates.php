<?php

namespace App\Livewire\Network;

use App\Actions\Sites\ManageTls;
use App\Actions\SyncCertificatesAndTasks;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Resources\CertificateResource;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Display\Placeholder;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListCertificates extends ListPage
{
    protected static ?string $resource = CertificateResource::class;

    protected ?string $title = 'SSL certifikáty';

    public function getSubheading(): ?string
    {
        return "Veřejné domény: Let's Encrypt, vydává a obnovuje se samo. Domény ve vnitřní síti: interní certifikační autorita serveru. Nebo vlastní certifikát (web → Další → HTTPS / certifikát).";
    }

    protected function headerActions(): array
    {
        return [
            Action::make('rootCa')
                ->label('Kořenový certifikát (interní CA)')
                ->icon('outline:shield-check')
                ->color('gray')
                ->modalHeading('Kořenový certifikát interní certifikační autority')
                ->modalWidth('3xl')
                ->modalSubmitActionLabel('Zavřít')
                ->form(function () {
                    try {
                        $ca = app(ManageTls::class)->rootCa();
                        $error = null;
                    } catch (AgentException $e) {
                        $ca = null;
                        $error = $e->getMessage();
                    }

                    return [Placeholder::make('ca')->hiddenLabel()->html(view('livewire.root-ca', ['ca' => $ca, 'error' => $error])->render())];
                })
                ->action(fn () => null),
            Action::make('sync')->label('Ověřit stav')->icon('outline:arrow-path')
                ->action(function () {
                    try {
                        Toast::success('Ověřeno '.app(SyncCertificatesAndTasks::class)->certificates().' domén');
                    } catch (AgentException $e) {
                        Toast::error('Agent není dostupný', $e->getMessage());
                    }
                }),
        ];
    }
}
