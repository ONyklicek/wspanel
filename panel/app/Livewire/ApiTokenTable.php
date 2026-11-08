<?php

namespace App\Livewire;

use App\Models\ApiToken;
use App\Support\Toast;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireForms\Components\Toggle;
use NyonCode\WireTable\Columns\BadgeColumn;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Concerns\WithTable;
use NyonCode\WireTable\Table;

/** The signed-in user's API tokens. */
class ApiTokenTable extends Component
{
    use WithTable;

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->query(ApiToken::query())
            ->columns([
                TextColumn::make('name')->label('Název')->weight('medium'),
                BadgeColumn::make('can_write')->label('Smí')->state(fn (ApiToken $t) => $t->can_write ? 'číst i měnit' : 'jen číst')
                    ->colorUsing(fn ($state) => $state === 'jen číst' ? 'gray' : 'warning'),
                TextColumn::make('last_used_at')->label('Naposledy')->formatStateUsing(fn ($state) => $state?->diffForHumans() ?? 'nepoužit'),
                TextColumn::make('expires_at')->label('Platí do')->formatStateUsing(fn ($state) => $state?->format('j. n. Y') ?? 'bez omezení'),
            ])
            ->headerActions([
                Action::make('create')->label('Nový token')->icon('outline:plus')
                    ->form([
                        TextInput::make('name')->label('Název')->placeholder('notebook, GitHub Actions…')->required()->rules(['string', 'max:100']),
                        Toggle::make('can_write')->label('Smí i měnit (deploy, restart)')->default(false),
                        Select::make('days')->label('Platnost')->options(['30' => '30 dní', '90' => '90 dní', '365' => 'rok', '0' => 'bez omezení'])
                            ->default('90')->rules(['in:30,90,365,0'])->required(),
                    ])
                    ->action(function (array $data) {
                        [, $plain] = ApiToken::issue(auth()->user(), $data['name'], (bool) $data['can_write'], (int) $data['days'] ?: null);
                        Toast::secret('Token '.$data['name'].' (zobrazený jen teď)', $plain);
                    }),
            ])
            ->actions([
                Action::make('revoke')->label('Zrušit')->icon('outline:trash')->color('danger')->requiresConfirmation()
                    ->action(function (ApiToken $record) {
                        $record->delete();
                        Toast::success('Token zrušen');
                    }),
            ])
            ->emptyState('Zatím žádný token', 'Vytvořte token pro skripty nebo příkaz wspanel.', 'outline:key')
            ->searchable(false)
            ->paginated(false);
    }

    public function render(): View
    {
        return view('livewire.api-token-table');
    }
}
