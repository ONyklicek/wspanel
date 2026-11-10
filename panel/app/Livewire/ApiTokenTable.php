<?php

namespace App\Livewire;

use App\Models\ApiToken;
use App\Support\Toast;
use App\Wire\Actions\Action;
use Illuminate\Contracts\View\View;
use Livewire\Component;
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
                TextColumn::make('name')->label(__('Název'))->weight('medium'),
                BadgeColumn::make('can_write')->label(__('Smí'))->state(fn (ApiToken $t) => $t->can_write ? __('číst i měnit') : __('jen číst'))
                    ->colorUsing(fn ($state) => $state === __('jen číst') ? 'gray' : 'warning'),
                TextColumn::make('last_used_at')->label(__('Naposledy'))->formatStateUsing(fn ($state) => $state?->diffForHumans() ?? __('nepoužit')),
                TextColumn::make('expires_at')->label(__('Platí do'))->formatStateUsing(fn ($state) => $state?->format('j. n. Y') ?? __('bez omezení')),
            ])
            ->headerActions([
                Action::make('create')->label(__('Nový token'))->icon('outline:plus')
                    ->form([
                        TextInput::make('name')->label(__('Název'))->placeholder('notebook, GitHub Actions…')->required()->rules(['string', 'max:100']),
                        Toggle::make('can_write')->label(__('Smí i měnit (deploy, restart)'))->default(false),
                        Select::make('days')->label(__('Platnost'))->options(['30' => __('30 dní'), '90' => __('90 dní'), '365' => __('rok'), '0' => __('bez omezení')])
                            ->default('90')->rules(['in:30,90,365,0'])->required(),
                    ])
                    ->action(function (array $data) {
                        [, $plain] = ApiToken::issue(auth()->user(), $data['name'], (bool) $data['can_write'], (int) $data['days'] ?: null);
                        Toast::secret(__('Token ').$data['name'].__(' (zobrazený jen teď)'), $plain);
                    }),
            ])
            ->actions([
                Action::make('revoke')->label(__('Zneplatnit'))->icon('outline:trash')->color('danger')->requiresConfirmation()
                    ->modalHeading(fn (ApiToken $record) => __('Zneplatnit token :name?', ['name' => $record->name]))
                    ->modalDescription(__('Skripty a příkaz wspanel s tímto tokenem přestanou fungovat.'))
                    ->action(function (ApiToken $record) {
                        $record->delete();
                        Toast::success(__('Token zneplatněn'));
                    }),
            ])
            ->emptyState(__('Zatím žádný token'), __('Vytvořte token pro skripty nebo příkaz wspanel.'), 'outline:key')
            ->searchable(false)
            ->paginated(false);
    }

    public function render(): View
    {
        return view('livewire.api-token-table');
    }
}
