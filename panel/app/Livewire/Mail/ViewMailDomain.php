<?php

namespace App\Livewire\Mail;

use App\Actions\Mail\ManageMailHosting;
use App\Models\MailDomain;
use App\Services\Agent\AgentException;
use App\Services\Mail\Deliverability;
use App\Support\Toast;
use App\Wire\Resources\MailDomainResource;
use Illuminate\Contracts\View\View;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Infolists\Infolist;
use NyonCode\WireForms\Components\Display\Placeholder;
use NyonCode\WireForms\Components\Textarea;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireForms\Components\Toggle;
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
        /** @var MailDomain $domain */
        $domain = $this->nativeRecord();

        return [
            Action::make('features')
                ->label('Webmail a nastavení programů')
                ->icon('outline:cog-6-tooth')
                ->fillFormUsing(fn () => ['webmail' => $domain->webmail, 'autoconfig' => $domain->autoconfig])
                ->form([
                    Toggle::make('webmail')->label('Webmail na webmail.'.$domain->domain)
                        ->helperText('Přihlášení jen jménem schránky (bez @'.$domain->domain.'). Potřebuje DNS záznam webmail.'.$domain->domain.'.'),
                    Toggle::make('autoconfig')->label('Automatické nastavení poštovních programů')
                        ->helperText('Thunderbird a Outlook si server najdou samy (autoconfig.'.$domain->domain.', autodiscover.'.$domain->domain.').'),
                ])
                ->action(function (array $data) use ($domain) {
                    try {
                        app(ManageMailHosting::class)->setFeatures($domain, (bool) $data['webmail'], (bool) $data['autoconfig']);
                        Toast::success('Uloženo', 'Certifikát pro nové adresy server získá, jakmile na ně ukazuje DNS.');
                    } catch (AgentException $e) {
                        Toast::error('Nastavení se nepodařilo uložit', $e->getMessage());
                    }
                }),
            Action::make('spam')
                ->label('Antispam')
                ->icon('outline:no-symbol')
                ->color('gray')
                ->modalDescription('Rspamd hodnotí každou zprávu body. Bez vlastního nastavení platí serverové: odmítnout od 15, do složky Junk od 6.')
                ->fillFormUsing(fn () => ['custom' => $domain->spam !== null, 'reject' => $domain->spam['reject'] ?? 15, 'junk' => $domain->spam['junk'] ?? 6,
                    'allow' => implode("\n", $domain->spam['allow'] ?? []), 'block' => implode("\n", $domain->spam['block'] ?? [])])
                ->form([
                    Toggle::make('custom')->label('Vlastní nastavení pro doménu')->live(),
                    TextInput::make('junk')->label('Do složky Junk od (bodů)')->numeric()->visibleWhen('custom', true)->rules(['nullable', 'numeric', 'between:1,99']),
                    TextInput::make('reject')->label('Odmítnout od (bodů)')->numeric()->visibleWhen('custom', true)->rules(['nullable', 'numeric', 'between:5,100']),
                    Textarea::make('allow')->label('Vždy přijmout od')->rows(3)->visibleWhen('custom', true)
                        ->helperText('Adresa nebo @doména na řádek; tyto zprávy se nekontrolují.'),
                    Textarea::make('block')->label('Vždy odmítnout od')->rows(3)->visibleWhen('custom', true)
                        ->helperText('Adresa nebo @doména na řádek.'),
                ])
                ->action(function (array $data) use ($domain) {
                    $list = fn ($v) => array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $v) ?: [])));
                    try {
                        app(ManageMailHosting::class)->setSpam($domain, $data['custom'] ? [
                            'reject' => (float) $data['reject'], 'junk' => (float) $data['junk'], 'allow' => $list($data['allow'] ?? ''), 'block' => $list($data['block'] ?? ''),
                        ] : null);
                        Toast::success('Antispam uložen');
                    } catch (AgentException $e) {
                        Toast::error('Nastavení se nepodařilo uložit', $e->getMessage());
                    }
                }),
            Action::make('deliverability')
                ->label('Kontrola doručitelnosti')
                ->icon('outline:shield-check')
                ->color('gray')
                ->modalHeading('Doručitelnost pošty '.$domain->domain)
                ->modalDescription('Co kontrolují ostatní servery, než poštu přijmou, a co ji přivede sem.')
                ->modalSubmitActionLabel('Zavřít')
                ->form(fn () => [Placeholder::make('result')->hiddenLabel()->html(view('livewire.dns.check', [
                    'checks' => app(Deliverability::class)->check($domain),
                ])->render())])
                ->action(fn () => null),
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
            'settings' => ManageMailHosting::clientSettings($domain),
        ]);
    }
}
