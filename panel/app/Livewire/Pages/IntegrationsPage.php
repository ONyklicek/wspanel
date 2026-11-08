<?php

namespace App\Livewire\Pages;

use App\Actions\Sites\ManageGitHub;
use App\Livewire\Concerns\TranslatesPageText;
use App\Models\GithubConnection;
use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\GitHubException;
use App\Support\Toast;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Display\Placeholder;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WirePanels\Pages\Page;

/**
 * Services the signed-in user connects to the panel. GitHub: connect with a
 * button (the panel's GitHub App), then pick a repository at any site's
 * Deploy page.
 */
class IntegrationsPage extends Page
{
    use TranslatesPageText;

    protected static string $view = 'livewire.pages.integrations';

    protected ?string $title = 'Integrace';

    protected static ?string $slug = 'integrations';

    protected static ?string $navigationLabel = 'Integrace';

    protected static ?string $navigationIcon = 'outline:puzzle-piece';

    protected static ?string $navigationGroup = 'account';

    protected static int $navigationSort = 15;

    public function githubApp(): ?GitHubApp
    {
        return GitHubApp::configured();
    }

    /** @return Collection<int, GithubConnection> */
    public function connections(): Collection
    {
        return GithubConnection::query()->withCount('sites')->orderBy('login')->get();
    }

    public function isAdmin(): bool
    {
        return Gate::allows('admin');
    }

    protected function actions(): array
    {
        $actions = [$this->connectAction(), $this->createAppAction(), $this->organizationAppAction(), $this->tokenAction()];
        foreach ($this->connections() as $c) {
            $actions[] = $this->manageAction($c);
            $actions[] = $this->disconnectAction($c);
        }

        return $actions;
    }

    /** The button: install the app on an account or organisation and come back. */
    public function connectAction(): Action
    {
        return Action::make('connect')
            ->label(fn () => $this->connections()->isEmpty() ? __('Propojit s GitHubem') : __('Přidat účet nebo organizaci'))
            ->icon('outline:link')
            ->url(route('github.connect'))->navigate(false);
    }

    /** First connection by the administrator: creates the panel's app, then installs it. */
    public function createAppAction(): Action
    {
        return Action::make('createApp')
            ->label(__('Propojit s GitHubem'))
            ->icon('outline:link')
            ->url(route('github.app.create'))->navigate(false);
    }

    public function organizationAppAction(): Action
    {
        return Action::make('organizationApp')
            ->label(__('Aplikace pro organizaci'))
            ->color('gray')
            ->quiet()
            ->modalHeading(__('GitHub aplikace patřící organizaci'))
            ->modalWidth('lg')
            ->form([
                Placeholder::make('how')->hiddenLabel()->content(__('Aplikaci panelu může vlastnit organizace místo vašeho účtu: spravuje ji pak kdokoli z vlastníků organizace. Instalovat ji mohou všichni uživatelé panelu na své účty.')),
                TextInput::make('org')->label(__('Organizace na GitHubu'))->required()->placeholder('acme')
                    ->rules(['regex:/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/']),
            ])
            ->modalSubmitActionLabel(__('Pokračovat na GitHub'))
            ->action(fn (array $data) => $this->redirect(route('github.app.create', ['org' => $data['org']])));
    }

    /** Without the app (GitHub Enterprise, apps forbidden in the organisation): a fine-grained token. */
    public function tokenAction(): Action
    {
        return Action::make('token')
            ->label(__('Použít osobní token'))
            ->color('gray')
            ->quiet()
            ->modalHeading(__('Připojit GitHub tokenem'))
            ->modalWidth('2xl')
            ->form([
                Placeholder::make('how')->hiddenLabel()->content(__('GitHub → Settings → Developer settings → Fine-grained tokens → Generate new token. Repository access: repozitáře, které chcete nasazovat. Oprávnění (Repository permissions): Administration, Webhooks a Deployments na Read and write, Contents a Metadata na Read-only; Secrets na Read and write, pokud má panel ukládat CI token. Token je uložený šifrovaně a už se nikde nezobrazí.')),
                TextInput::make('token')->label(__('Token'))->password()->required()
                    ->rules(['string', 'max:255', 'regex:/^(github_pat_|ghp_)[A-Za-z0-9_]+$/'])
                    ->validationMessages(['regex' => __('Token GitHubu začíná github_pat_ (fine-grained) nebo ghp_ (classic).')]),
            ])
            ->modalSubmitActionLabel(__('Připojit'))
            ->action(function (array $data) {
                try {
                    $c = app(ManageGitHub::class)->connect(auth()->user(), $data['token']);
                    Toast::success(__('GitHub připojen'), '@'.$c->login);
                } catch (GitHubException $e) {
                    Toast::error(__('GitHub se nepodařilo připojit'), $e->getMessage());
                }
            });
    }

    public function manageAction(GithubConnection $c): Action
    {
        return Action::make('manage_'.$c->id)
            ->label(__('Spravovat přístup'))
            ->icon('outline:arrow-top-right-on-square')
            ->color('gray')
            ->size('sm')
            ->visible($c->isApp())
            ->url((string) $c->settingsUrl(), openInNewTab: true);
    }

    public function disconnectAction(GithubConnection $c): Action
    {
        return Action::make('disconnect_'.$c->id)
            ->label(__('Odpojit'))
            ->color('danger')
            ->size('sm')
            ->outlined()
            ->requiresConfirmation()
            ->modalHeading(__('Odpojit @:login?', ['login' => $c->login]))
            ->modalDescription(__('Panel z napojených repozitářů odebere svůj webhook a deploy klíč a zapomene připojení. Aplikaci z účtu odinstalujete na GitHubu (Settings → Applications). Nastavení deploye webů zůstane.'))
            ->modalSubmitActionLabel(__('Odpojit'))
            ->action(function () use ($c) {
                app(ManageGitHub::class)->disconnect($c);
                Toast::success(__('GitHub odpojen'), '@'.$c->login);
            });
    }
}
