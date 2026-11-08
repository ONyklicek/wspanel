<?php

namespace App\Livewire\Mail;

use App\Actions\Mail\ManageMailHosting;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Resources\MailDomainResource;
use Illuminate\Validation\Rule;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Select;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListMailDomains extends ListPage
{
    protected static ?string $resource = MailDomainResource::class;

    public function getSubheading(): ?string
    {
        return app(ManageMailHosting::class)->installed()
            ? 'Server přijímá poštu pro tyto domény. Do DNS domény patří MX záznam na '.ManageMailHosting::mailHost().'.'
            : 'Příjem pošty není nainstalovaný (Server → Moduly).';
    }

    protected function headerActions(): array
    {
        return [
            Action::make('addDomain')
                ->label('Přidat doménu')
                ->icon('outline:plus')
                ->visible(fn () => app(ManageMailHosting::class)->installed())
                ->form([
                    Select::make('domain')->label('Doména')->required()
                        ->options(fn () => Site::query()->orderBy('domain')->pluck('domain', 'domain')->all())
                        ->helperText('Doména některého z vašich webů.')
                        ->rules([fn () => Rule::in(Site::query()->pluck('domain')->all())]),
                ])
                ->action(function (array $data) {
                    try {
                        $d = app(ManageMailHosting::class)->addDomain($data['domain']);
                        Toast::success('Doména přidána', 'Založte v ní schránky. MX záznam: '.ManageMailHosting::mailHost());
                        $this->redirect(MailDomainResource::url('view', $d));
                    } catch (AgentException $e) {
                        Toast::error('Doménu se nepodařilo přidat', $e->getMessage());
                    }
                }),
        ];
    }
}
