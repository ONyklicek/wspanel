<?php

namespace App\Livewire\Mail;

use App\Actions\Mail\ManageMailHosting;
use App\Livewire\Concerns\TranslatesPageText;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Resources\MailDomainResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WirePanels\Resources\Pages\ListPage;

class ListMailDomains extends ListPage
{
    use TranslatesPageText;

    protected static ?string $resource = MailDomainResource::class;

    public function getSubheading(): ?string
    {
        return app(ManageMailHosting::class)->installed()
            ? __('Server přijímá poštu pro tyto domény. Do DNS domény patří MX záznam na ').ManageMailHosting::mailHost().'.'
            : __('Příjem pošty není nainstalovaný (Server → Moduly).');
    }

    protected function headerActions(): array
    {
        return [
            Action::make('addDomain')
                ->label(__('Přidat doménu'))
                ->icon('outline:plus')
                ->visible(fn () => app(ManageMailHosting::class)->installed())
                ->form([
                    Select::make('domain')->label(__('Doména'))->required()
                        ->options(fn () => Site::query()->orderBy('domain')->pluck('domain', 'domain')->all())
                        ->helperText(__('Doména některého z vašich webů.'))
                        ->rules([fn () => Rule::in(Site::query()->pluck('domain')->all())]),
                ])
                ->action(function (array $data) {
                    try {
                        $d = app(ManageMailHosting::class)->addDomain($data['domain']);
                        Toast::success(__('Doména přidána'), __('Založte v ní schránky. MX záznam: ').ManageMailHosting::mailHost());
                        $this->redirect(MailDomainResource::url('view', $d));
                    } catch (AgentException $e) {
                        Toast::error(__('Doménu se nepodařilo přidat'), $e->getMessage());
                    }
                }),
            Action::make('limits')
                ->label(__('Limity odesílání'))
                ->icon('outline:paper-airplane')
                ->color('gray')
                ->visible(fn () => Gate::allows('admin') && app(ManageMailHosting::class)->installed())
                ->modalDescription(__('Kolik zpráv za hodinu smí odeslat jedna schránka a jeden web. Ukradené heslo nebo napadený web jinak rozešle spam, a adresa serveru skončí na blacklistech. Nad limit server zprávu dočasně odmítne a odesílatel ji zkusí později.'))
                ->fillFormUsing(fn () => app(ManageMailHosting::class)->limits())
                ->form([
                    TextInput::make('mailbox_per_hour')->label(__('Schránka (zpráv za hodinu)'))->integer()->required()
                        ->rules(['integer', 'between:0,100000'])->helperText(__('Odeslání z poštovních programů a webmailu. 0 = bez limitu.')),
                    TextInput::make('site_per_hour')->label(__('Web (zpráv za hodinu)'))->integer()->required()
                        ->rules(['integer', 'between:0,100000'])->helperText(__('Pošta webů přes SMTP (Laravel, Symfony, Node). PHP mail() limit nemá. 0 = bez limitu.')),
                ])
                ->action(function (array $data) {
                    try {
                        app(ManageMailHosting::class)->setLimits((int) $data['mailbox_per_hour'], (int) $data['site_per_hour']);
                        Toast::success(__('Limity uloženy'));
                    } catch (AgentException $e) {
                        Toast::error(__('Limity se nepodařilo uložit'), $e->getMessage());
                    }
                }),
        ];
    }
}
