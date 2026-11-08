<?php

namespace App\Livewire\Mail;

use App\Actions\Mail\ManageMailHosting;
use App\Models\MailDomain;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Resources\MailDomainResource;
use Illuminate\Contracts\View\View;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Infolists\Infolist;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

/** A mail domain: its mailboxes, aliases and what mail clients need. */
class ViewMailDomain extends ViewPage
{
    protected static ?string $resource = MailDomainResource::class;

    public function getTitle(): ?string
    {
        $d = $this->nativeRecord();

        return $d instanceof MailDomain ? 'Pošta · '.$d->domain : 'Pošta';
    }

    public function infolist(): Infolist
    {
        return Infolist::make()->schema([]);
    }

    protected function headerActions(): array
    {
        return [
            Action::make('deleteDomain')
                ->label('Odebrat doménu')
                ->icon('outline:trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Server přestane přijímat poštu pro tuto doménu. Jde to, jen když v ní není žádná schránka.')
                ->action(function () {
                    try {
                        app(ManageMailHosting::class)->removeDomain($this->nativeRecord());
                        Toast::success('Doména odebrána');
                        $this->redirect(MailDomainResource::url('index'));
                    } catch (AgentException $e) {
                        Toast::error('Doménu nejde odebrat', $e->getMessage());
                    }
                }),
        ];
    }

    public function render(): View
    {
        /** @var MailDomain $domain */
        $domain = $this->nativeRecord();
        app(ManageMailHosting::class)->refreshUsage($domain);

        return view('livewire.mail.view-domain', [
            'title' => $this->getTitle(),
            'breadcrumbs' => $this->breadcrumbs(),
            'headerActions' => $this->renderedHeaderActions(),
            'subNavigation' => $this->subNavigation($domain),
            'domain' => $domain,
            'settings' => ManageMailHosting::clientSettings(),
        ]);
    }
}
