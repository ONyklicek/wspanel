<?php

namespace App\Livewire\Pages;

use App\Actions\Mail\ManageMailHosting;
use App\Actions\Server\ManagePython;
use App\Actions\Sites\ManageXhprof;
use App\Http\Middleware\EnsureTwoFactor;
use App\Livewire\Concerns\TranslatesPageText;
use App\Models\Client;
use App\Models\Setting;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use App\Support\Features;
use App\Support\Lang;
use App\Support\Toast;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Radio;
use NyonCode\WireForms\Components\Select;
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

    /** @return list<string> installed Python versions, newest first */
    public function pythonVersions(): array
    {
        return array_values(array_keys(app(SystemInfo::class)->pythonVersionOptions()));
    }

    /** @return list<string> installed Python versions with the exact build, newest first ("Python 3.13.18") */
    public function pythonLabels(): array
    {
        $patches = app(SystemInfo::class)->pythonPatches();

        return array_map(fn (string $v) => 'Python '.($patches[$v] ?? $v), $this->pythonVersions());
    }

    /** @return array<string, string> versions the server can still install */
    private function installablePython(): array
    {
        $system = app(SystemInfo::class);

        return collect($system->installablePython())
            ->diff(array_keys($system->pythonVersionOptions()))
            ->sortDesc(SORT_NATURAL)
            ->mapWithKeys(fn (string $v) => [$v => "Python {$v}"])
            ->all();
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
                            ? Toast::success(__('Pošta nainstalována'), __('Přidejte domény v Pošta → Domény a schránky.'))
                            : Toast::warning(__('Instalace ještě běží'), __('Stav se ukáže za chvíli.'));
                    } catch (AgentException $e) {
                        Toast::error(__('Instalace selhala'), $e->getMessage());
                    }
                    $this->redirect(self::url());
                }),
            Action::make('installPython')
                ->label(fn () => $this->pythonVersions() === [] ? __('Nainstalovat Python') : __('Přidat verzi Pythonu'))
                ->icon('outline:command-line')
                ->visible(fn () => $this->installablePython() !== [])
                ->modalDescription(__('Stáhne uv (správce Pythonu od Astralu, ověřený otiskem) a zvolenou verzi CPythonu do /opt/uv/python. Pak půjde zakládat weby v režimu Python (Django, Flask, FastAPI). Trvá zhruba minutu.'))
                ->form(fn () => [
                    Select::make('version')->label(__('Verze'))->options($this->installablePython())
                        ->default(array_key_first($this->installablePython()))->required()
                        ->rules([fn () => 'in:'.implode(',', array_keys($this->installablePython()))]),
                ])
                ->action(function (array $data) {
                    try {
                        app(ManagePython::class)->install((string) $data['version'])
                            ? Toast::success(__('Python :version nainstalován', ['version' => $data['version']]), __('Při zakládání webu zvolte režim Python.'))
                            : Toast::warning(__('Instalace ještě běží'), __('Stav se ukáže za chvíli.'));
                    } catch (AgentException $e) {
                        Toast::error(__('Instalace selhala'), $e->getMessage());
                    }
                    $this->redirect(self::url());
                }),
            Action::make('upgradePython')
                ->label(__('Aktualizovat Python'))
                ->icon('outline:arrow-path')
                ->color('gray')
                ->visible(fn () => $this->pythonVersions() !== [])
                ->modalDescription(__('Stáhne nejnovější opravné vydání zvolené verze (např. 3.13.5 → 3.13.18, bezpečnostní opravy). Virtualenvy webů zůstanou, weby na této verzi se restartují na nový interpret (krátce přeruší spojení) a staré sestavení se smaže.'))
                ->form(fn () => [
                    Select::make('version')->label(__('Verze'))
                        ->options(array_combine($this->pythonVersions(), $this->pythonLabels()))
                        ->default($this->pythonVersions()[0] ?? null)->required()
                        ->rules([fn () => 'in:'.implode(',', $this->pythonVersions())]),
                ])
                ->action(function (array $data) {
                    try {
                        $out = app(ManagePython::class)->upgrade((string) $data['version']);
                        match (true) {
                            $out === null => Toast::warning(__('Aktualizace ještě běží'), __('Stav se ukáže za chvíli.')),
                            ! ($out['changed'] ?? false) => Toast::success(__('Python je aktuální'), __('Python :version je na nejnovějším opravném vydání.', ['version' => $out['from'] ?? $data['version']])),
                            default => Toast::success(
                                __('Python aktualizován na :version', ['version' => $out['to'] ?? '']),
                                ($out['restarted'] ?? []) === [] ? __('Žádný web na této verzi neběží.')
                                    : __('Restartované služby:').' '.Lang::count(count($out['restarted']), ':count služba', ':count služby', ':count služeb'),
                            ),
                        };
                    } catch (AgentException $e) {
                        Toast::error(__('Aktualizace selhala'), $e->getMessage());
                    }
                    $this->redirect(self::url());
                }),
            Action::make('installXhprof')
                ->label(__('Nainstalovat XHProf + XHGui'))
                ->icon('outline:chart-bar')
                ->visible(fn () => ! app(ManageXhprof::class)->installed())
                ->requiresConfirmation()
                ->modalDescription(__('Profilování PHP: rozšíření XHProf pro všechny verze PHP a XHGui pro prohlížení výsledků. Zapíná se u každého webu zvlášť (Nastavení → Výkon a ladění → XHProf), načte se jen do poolu webů, které ho mají zapnuté.'))
                ->action(function () {
                    try {
                        app(ManageXhprof::class)->install()
                            ? Toast::success(__('XHProf nainstalován'), __('Zapnete ho u webu v menu Nastavení → Výkon a ladění → XHProf.'))
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
