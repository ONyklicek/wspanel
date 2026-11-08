<?php

namespace App\Livewire\Pages;

use App\Actions\Mail\ManageMailHosting;
use App\Actions\Sites\ManageXhprof;
use App\Http\Middleware\EnsureTwoFactor;
use App\Livewire\Concerns\TranslatesPageText;
use App\Models\Client;
use App\Models\Setting;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use App\Support\Features;
use App\Support\Toast;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Radio;
use NyonCode\WirePanels\Pages\Page;

/**
 * Optional parts of the panel. Chosen at installation (install.sh), switched
 * here later, like the database engines that can be installed afterwards.
 */
class ModulesPage extends Page
{
    use TranslatesPageText;

    protected static string $view = 'livewire.pages.modules';

    /** Server-wide settings: administrators only. */
    protected static ?string $permission = 'admin';

    protected ?string $title = 'Moduly';

    protected static ?string $slug = 'modules';

    protected static ?string $navigationLabel = 'Moduly';

    protected static ?string $navigationIcon = 'outline:puzzle-piece';

    protected static ?string $navigationGroup = 'server';

    protected static int $navigationSort = 90;

    public function clientsEnabled(): bool
    {
        return Features::clients();
    }

    public function clientCount(): int
    {
        return Client::query()->count();
    }

    /** @return list<string> */
    public function dbEngines(): array
    {
        return array_map(fn ($e) => $e->getLabel(), rescue(fn () => app(SystemInfo::class)->dbEngines(), [], report: false));
    }

    public function xhprof(): bool
    {
        return app(ManageXhprof::class)->installed();
    }

    public function mailHosting(): bool
    {
        return app(ManageMailHosting::class)->installed();
    }

    protected function headerActions(): array
    {
        return [
            Action::make('installMail')
                ->label(__('Nainstalovat poštu (schránky)'))
                ->icon('outline:inbox-stack')
                ->visible(fn () => ! app(ManageMailHosting::class)->installed())
                ->requiresConfirmation()
                ->modalDescription(__('Doinstaluje Dovecot (IMAP, POP3, Sieve) a Rspamd (antispam), otevře porty 25, 465, 587, 993, 995 a 4190 a Postfix začne přijímat poštu pro přidané domény. Trvá pár minut.'))
                ->action(function () {
                    try {
                        app(ManageMailHosting::class)->install()
                            ? Toast::success(__('Pošta nainstalována'), __('Přidejte domény v Pošta → Schránky.'))
                            : Toast::warning(__('Instalace ještě běží'), __('Stav se ukáže za chvíli.'));
                    } catch (AgentException $e) {
                        Toast::error(__('Instalace selhala'), $e->getMessage());
                    }
                    $this->redirect(self::url());
                }),
            Action::make('installXhprof')
                ->label(__('Nainstalovat XHProf + XHGui'))
                ->icon('outline:chart-bar')
                ->visible(fn () => ! app(ManageXhprof::class)->installed())
                ->requiresConfirmation()
                ->modalDescription(__('Profilování PHP: rozšíření XHProf pro všechny verze PHP a XHGui pro prohlížení výsledků. Zapíná se u každého webu zvlášť (Další → Profilování), načte se jen do poolu webů, které ho mají zapnuté.'))
                ->action(function () {
                    try {
                        app(ManageXhprof::class)->install()
                            ? Toast::success(__('XHProf nainstalován'), __('Zapnete ho u webu v menu Další → Profilování.'))
                            : Toast::warning(__('Instalace ještě běží'), __('Stav se ukáže za chvíli.'));
                    } catch (AgentException $e) {
                        Toast::error(__('Instalace selhala'), $e->getMessage());
                    }
                    $this->redirect(self::url());
                }),
            Action::make('twoFactor')
                ->label(__('Dvoufázové přihlášení'))
                ->icon('outline:lock-closed')
                ->color('gray')
                ->modalDescription(__('Kdo musí mít zapnuté dvoufázové přihlášení (aplikace v telefonu). Uživatel bez něj se po přihlášení dostane jen do profilu, kde si ho nastaví.'))
                ->fillFormUsing(fn () => ['policy' => (string) (Setting::get(EnsureTwoFactor::SETTING) ?? 'none')])
                ->form([
                    Radio::make('policy')->label(__('Vyžadovat'))->options(['none' => __('Nikoho'), 'clients' => __('Klienty'), 'all' => __('Všechny (i správce)')])
                        ->rules(['in:none,clients,all'])->required(),
                ])
                ->action(function (array $data) {
                    Setting::put(EnsureTwoFactor::SETTING, $data['policy']);
                    Toast::success(__('Uloženo'));
                }),
            Action::make('enableClients')
                ->label(__('Zapnout klienty a balíčky'))
                ->icon('outline:user-group')
                ->visible(fn () => ! Features::clients())
                ->requiresConfirmation()
                ->modalDescription(__('V menu Hosting přibudou Klienti a Balíčky; weby, databáze a DNS zóny pak můžete přidělovat klientům, kteří se přihlásí jen ke svým věcem.'))
                ->action(function () {
                    Features::setClients(true);
                    Toast::success(__('Klienti a balíčky zapnuty'));
                    $this->redirect(self::url());
                }),
            Action::make('disableClients')
                ->label(__('Vypnout klienty a balíčky'))
                ->icon('outline:user-group')
                ->color('gray')
                ->visible(fn () => Features::clients())
                ->requiresConfirmation()
                ->action(function () {
                    if (Client::query()->exists()) {
                        Toast::error(__('Nejde vypnout'), __('Nejdřív smažte všechny klienty (Hosting → Klienti).'));

                        return;
                    }
                    Features::setClients(false);
                    Toast::success(__('Klienti a balíčky vypnuty'));
                    $this->redirect(self::url());
                }),
        ];
    }
}
