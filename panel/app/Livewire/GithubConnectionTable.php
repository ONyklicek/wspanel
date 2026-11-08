<?php

namespace App\Livewire;

use App\Actions\Sites\ManageGitHub;
use App\Models\GithubConnection;
use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\GitHubException;
use App\Support\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Display\Placeholder;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Concerns\WithTable;
use NyonCode\WireTable\Table;

/** The signed-in user's connected GitHub accounts. */
class GithubConnectionTable extends Component
{
    use WithTable;

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->query(GithubConnection::query()->withCount('sites'))
            ->columns([
                TextColumn::make('login')->label(__('Účet'))->weight('medium')->prefix('@'),
                TextColumn::make('installation_id')->label(__('Připojeno'))->formatStateUsing(fn ($state) => $state ? __('aplikací (tlačítkem)') : __('tokenem')),
                TextColumn::make('sites_count')->label(__('Napojené weby')),
                TextColumn::make('last_used_at')->label(__('Naposledy'))->formatStateUsing(fn ($state) => $state?->diffForHumans() ?? __('nepoužit')),
            ])
            ->headerActions([
                Action::make('connectApp')->label(__('Připojit GitHub'))->icon('outline:link')
                    ->visible(fn () => GitHubApp::configured() !== null)
                    ->url(route('github.connect'))->navigate(false),
                Action::make('createApp')->label(__('Nastavit připojení tlačítkem'))->icon('outline:sparkles')
                    ->visible(fn () => GitHubApp::configured() === null && Gate::allows('admin'))
                    ->modalHeading(__('GitHub aplikace tohoto panelu'))
                    ->modalWidth('2xl')
                    ->form([
                        Placeholder::make('how')->hiddenLabel()->content(__('Jednou pro celý panel: GitHub podle připravené šablony vytvoří aplikaci tohoto panelu (stačí potvrdit), klíče předá panelu sám. Potom každý uživatel připojí GitHub tlačítkem a repozitáře vybere přímo na GitHubu. Aplikace je veřejná, aby ji klienti mohli nainstalovat na své účty; nic dalšího neumí.')),
                        TextInput::make('org')->label(__('Organizace (nepovinné)'))
                            ->helperText(__('Prázdné = aplikace patří vašemu účtu GitHub. Název organizace = patří organizaci.'))
                            ->rules(['nullable', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/']),
                    ])
                    ->modalSubmitActionLabel(__('Pokračovat na GitHub'))
                    ->action(fn (array $data) => $this->redirect(route('github.app.create', array_filter(['org' => $data['org'] ?? null])))),
                Action::make('connect')->label(fn () => GitHubApp::configured() ? __('Vložit token') : __('Připojit GitHub'))->icon('outline:key')
                    ->color(fn () => GitHubApp::configured() ? 'gray' : 'primary')
                    ->modalHeading(__('Připojit účet GitHub'))
                    ->modalWidth('2xl')
                    ->form([
                        Placeholder::make('how')->hiddenLabel()->content(__('GitHub → Settings → Developer settings → Fine-grained tokens → Generate new token. Repository access: repozitáře, které chcete nasazovat. Oprávnění (Repository permissions): Administration, Webhooks a Deployments na Read and write, Contents a Metadata na Read-only; Secrets na Read and write, pokud má panel ukládat CI token. Token je uložený šifrovaně a už se nikde nezobrazí.')),
                        TextInput::make('token')->label(__('Token'))->password()->required()
                            ->rules(['string', 'max:255', 'regex:/^(github_pat_|ghp_)[A-Za-z0-9_]+$/'])
                            ->validationMessages(['regex' => __('Token GitHubu začíná github_pat_ (fine-grained) nebo ghp_ (classic).')]),
                    ])
                    ->action(function (array $data) {
                        try {
                            $c = app(ManageGitHub::class)->connect(auth()->user(), $data['token']);
                            Toast::success(__('GitHub připojen'), '@'.$c->login);
                        } catch (GitHubException $e) {
                            Toast::error(__('GitHub se nepodařilo připojit'), $e->getMessage());
                        }
                    }),
            ])
            ->actions([
                Action::make('repos')->label(__('Repozitáře'))->icon('outline:adjustments-horizontal')->color('gray')
                    ->visible(fn (GithubConnection $record) => $record->isApp())
                    ->url(fn (GithubConnection $record) => route('github.connect'))->navigate(false),
                Action::make('disconnect')->label(__('Odpojit'))->icon('outline:trash')->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('Panel z napojených repozitářů odebere svůj webhook a deploy klíč, odinstaluje svou GitHub aplikaci z účtu (byla-li připojená tlačítkem) a zapomene token. Nastavení deploye webů zůstane.'))
                    ->action(function (GithubConnection $record) {
                        app(ManageGitHub::class)->disconnect($record);
                        Toast::success(__('GitHub odpojen'), '@'.$record->login);
                    }),
            ])
            ->emptyState(__('Žádný účet GitHub'), __('Po připojení vyberete u webu repozitář ze seznamu a panel sám přidá deploy klíč a webhook.'), 'outline:link')
            ->searchable(false)
            ->paginated(false);
    }

    public function render(): View
    {
        return view('livewire.github-connection-table');
    }
}
