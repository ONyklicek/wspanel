<?php

namespace App\Livewire\Mail;

use App\Actions\Mail\ManageMailHosting;
use App\Livewire\Concerns\TranslatesPageText;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\AgentTasks;
use App\Support\Toast;
use App\Wire\Actions\Action;
use App\Wire\Resources\MailDomainResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireForms\Components\Toggle;
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
                // The mail configs and OpenDKIM restart: in the background, the list shows it once done.
                ->action(fn (array $data) => AgentTasks::queue('mail.domain.add', ['title' => __('Zřízení pošty pro :domain', ['domain' => $data['domain']]), 'done' => __('Doména přidána')],
                    ManageMailHosting::class, 'addDomainQueued', [(string) $data['domain']], key: 'mail-domain:'.$data['domain'])),
            Action::make('filters')
                ->label(__('Ochrana pošty'))
                ->icon('outline:shield-check')
                ->color('gray')
                ->visible(fn () => Gate::allows('admin') && app(ManageMailHosting::class)->installed())
                ->modalDescription(__('Doplňky antispamu pro celý server. Rspamd hodnotí každou zprávu i bez nich.'))
                ->fillFormUsing(fn () => app(ManageMailHosting::class)->filters())
                ->form([
                    Toggle::make('greylist')->label(__('Greylisting'))
                        ->helperText(__('Podezřelou zprávu (od 4 bodů) od neznámého odesílatele server poprvé odmítne na 5 minut. Skutečné servery to zkusí znovu, většina spamu ne. Taková pošta pak dorazí o pár minut později.')),
                    Toggle::make('antivirus')->label(__('Antivir (ClamAV)'))
                        ->helperText(__('Každá příchozí zpráva se kontroluje na viry, nakažená se odmítne. ClamAV potřebuje asi 1 GB paměti; při zapnutí se nainstaluje a stáhne databázi virů (několik minut).')),
                ])
                ->action(function (array $data) {
                    abort_unless(Gate::allows('admin'), 403);
                    try {
                        app(ManageMailHosting::class)->setFilters((bool) $data['greylist'], (bool) $data['antivirus']);
                    } catch (AgentException $e) {
                        Toast::error(__('Ochranu pošty se nepodařilo uložit'), $e->getMessage());
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

    /** A domain was added in the background: the list anew (rendering is enough). */
    #[On('agent-task-finished')]
    public function mailTaskFinished(): void {}
}
