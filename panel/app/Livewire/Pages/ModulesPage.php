<?php

namespace App\Livewire\Pages;

use App\Actions\Mail\ManageMailHosting;
use App\Models\Client;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use App\Support\Features;
use App\Support\Toast;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WirePanels\Pages\Page;

/**
 * Optional parts of the panel. Chosen at installation (install.sh), switched
 * here later, like the database engines that can be installed afterwards.
 */
class ModulesPage extends Page
{
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

    public function mailHosting(): bool
    {
        return app(ManageMailHosting::class)->installed();
    }

    protected function headerActions(): array
    {
        return [
            Action::make('installMail')
                ->label('Nainstalovat poštu (schránky)')
                ->icon('outline:inbox-stack')
                ->visible(fn () => ! app(ManageMailHosting::class)->installed())
                ->requiresConfirmation()
                ->modalDescription('Doinstaluje Dovecot (IMAP, POP3, Sieve) a Rspamd (antispam), otevře porty 25, 465, 587, 993, 995 a 4190 a Postfix začne přijímat poštu pro přidané domény. Trvá pár minut.')
                ->action(function () {
                    try {
                        app(ManageMailHosting::class)->install()
                            ? Toast::success('Pošta nainstalována', 'Přidejte domény v Síť a pošta → Schránky.')
                            : Toast::warning('Instalace ještě běží', 'Stav se ukáže za chvíli.');
                    } catch (AgentException $e) {
                        Toast::error('Instalace selhala', $e->getMessage());
                    }
                    $this->redirect(self::url());
                }),
            Action::make('enableClients')
                ->label('Zapnout klienty a balíčky')
                ->icon('outline:user-group')
                ->visible(fn () => ! Features::clients())
                ->requiresConfirmation()
                ->modalDescription('V menu Hosting přibudou Klienti a Balíčky; weby, databáze a DNS zóny pak můžete přidělovat klientům, kteří se přihlásí jen ke svým věcem.')
                ->action(function () {
                    Features::setClients(true);
                    Toast::success('Klienti a balíčky zapnuty');
                    $this->redirect(self::url());
                }),
            Action::make('disableClients')
                ->label('Vypnout klienty a balíčky')
                ->icon('outline:user-group')
                ->color('gray')
                ->visible(fn () => Features::clients())
                ->requiresConfirmation()
                ->action(function () {
                    if (Client::query()->exists()) {
                        Toast::error('Nejde vypnout', 'Nejdřív smažte všechny klienty (Hosting → Klienti).');

                        return;
                    }
                    Features::setClients(false);
                    Toast::success('Klienti a balíčky vypnuty');
                    $this->redirect(self::url());
                }),
        ];
    }
}
