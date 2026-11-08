<?php

namespace App\Livewire;

use App\Enums\NotificationChannelType;
use App\Models\NotificationChannel;
use App\Services\Notify\Notifier;
use App\Support\Toast;
use App\Wire\Forms\Options;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Actions\ActionGroup;
use NyonCode\WireForms\Components\CheckboxList;
use NyonCode\WireForms\Components\Field;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireForms\Components\Toggle;
use NyonCode\WireTable\Columns\BadgeColumn;
use NyonCode\WireTable\Columns\TextColumn;
use NyonCode\WireTable\Concerns\WithTable;
use NyonCode\WireTable\Table;

/** The signed-in user's notification channels. */
class NotificationChannelTable extends Component
{
    use WithTable;

    /**
     * @param  array<string, mixed>  $data
     * @return array{address?: string, url?: string, token?: string, chat_id?: string}
     */
    public static function config(array $data): array
    {
        $type = NotificationChannelType::from((string) $data['type']);

        return match ($type) {
            NotificationChannelType::Email => ['address' => trim((string) $data['address'])],
            NotificationChannelType::Telegram => ['token' => trim((string) $data['token']), 'chat_id' => trim((string) $data['chat_id'])],
            default => (function () use ($type, $data) {
                $url = trim((string) $data['url']);
                if (! preg_match((string) $type->webhookPattern(), $url)) {
                    throw ValidationException::withMessages(['url' => 'Adresa webhooku '.$type->getLabel().' má jiný tvar.']);
                }

                return ['url' => $url];
            })(),
        };
    }

    /** @return list<Field> */
    private static function fields(): array
    {
        return [
            Select::make('type')->label('Kam')->options(NotificationChannelType::class)->rules([Options::rule(NotificationChannelType::class)])->default('email')->required()->live(),
            TextInput::make('name')->label('Název')->required()->rules(['string', 'max:100']),
            TextInput::make('address')->label('E-mail')->email()->visibleWhen('type', 'email')->requiredIf('type', 'email')->rules(['nullable', 'email:rfc', 'max:200']),
            TextInput::make('url')->label('Webhook URL')->visibleWhen('type', ['slack', 'discord'])->requiredIf('type', ['slack', 'discord'])
                ->helperText('Slack: Incoming Webhook (https://hooks.slack.com/…), Discord: Integrace → Webhook.')->rules(['nullable', 'url', 'max:300']),
            TextInput::make('token')->label('Token bota')->password()->revealable()->visibleWhen('type', 'telegram')->requiredIf('type', 'telegram')
                ->helperText('Od @BotFather.')->rules(['nullable', 'regex:/^[0-9]{5,15}:[A-Za-z0-9_-]{20,60}$/']),
            TextInput::make('chat_id')->label('Chat ID')->visibleWhen('type', 'telegram')->requiredIf('type', 'telegram')->rules(['nullable', 'regex:/^-?[0-9]{3,20}$|^@[A-Za-z0-9_]{5,40}$/']),
            CheckboxList::make('events')->label('Posílat')->options(NotificationChannel::EVENTS)->default(['problems', 'resolved'])->required(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->query(NotificationChannel::query())
            ->columns([
                TextColumn::make('name')->label('Název')->weight('medium'),
                TextColumn::make('type')->label('Kam')->formatStateUsing(fn ($state) => $state instanceof NotificationChannelType ? $state->getLabel() : $state),
                TextColumn::make('events')->label('Posílá')->state(fn (NotificationChannel $r) => collect($r->events ?? [])->map(fn ($e) => NotificationChannel::EVENTS[$e] ?? $e)->join(', ')),
                BadgeColumn::make('state')->label('Stav')
                    ->state(fn (NotificationChannel $r) => ! $r->enabled ? 'vypnuto' : ($r->last_error ? 'chyba' : 'aktivní'))
                    ->colorUsing(fn ($state) => ['aktivní' => 'success', 'chyba' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('last_error')->label('Poslední chyba')->limit(80)->placeholder('—'),
            ])
            ->headerActions([
                Action::make('create')->label('Přidat kanál')->icon('outline:plus')
                    ->form(self::fields())
                    ->action(function (array $data) {
                        NotificationChannel::create(['type' => $data['type'], 'name' => $data['name'], 'config' => self::config($data),
                            'events' => array_values($data['events'] ?? []), 'enabled' => true]);
                        Toast::success('Kanál přidán', 'Vyzkoušejte ho tlačítkem Zkusit.');
                    }),
            ])
            ->actions([
                Action::make('test')->label('Zkusit')->icon('outline:paper-airplane')->color('gray')
                    ->action(function (NotificationChannel $record) {
                        if (RateLimiter::tooManyAttempts('notify-test:'.$record->id, 3)) {
                            Toast::warning('Zkuste to za chvíli');

                            return;
                        }
                        RateLimiter::hit('notify-test:'.$record->id, 60);
                        app(Notifier::class)->send($record, 'Zkušební zpráva z WSPanelu', 'Takhle budou vypadat upozornění.')
                            ? Toast::success('Odesláno')
                            : Toast::error('Odeslání selhalo', (string) $record->fresh()->last_error);
                    }),
                ActionGroup::make([
                    Action::make('edit')->label('Upravit')->icon('outline:pencil')
                        ->fillFormUsing(fn (NotificationChannel $r) => ['name' => $r->name, 'events' => $r->events, 'enabled' => $r->enabled])
                        ->form([
                            TextInput::make('name')->label('Název')->required()->rules(['string', 'max:100']),
                            CheckboxList::make('events')->label('Posílat')->options(NotificationChannel::EVENTS)->required(),
                            Toggle::make('enabled')->label('Zapnuto'),
                        ])
                        ->action(function (NotificationChannel $record, array $data) {
                            $record->update(['name' => $data['name'], 'events' => array_values($data['events'] ?? []), 'enabled' => (bool) $data['enabled']]);
                            Toast::success('Uloženo');
                        }),
                    Action::make('delete')->label('Smazat')->icon('outline:trash')->color('danger')->requiresConfirmation()
                        ->action(function (NotificationChannel $record) {
                            $record->delete();
                            Toast::success('Kanál smazán');
                        }),
                ]),
            ])
            ->emptyState('Zatím žádný kanál', 'Přidejte e-mail, Slack, Discord nebo Telegram.', 'outline:bell')
            ->searchable(false)
            ->paginated(false);
    }

    public function render(): View
    {
        return view('livewire.notification-channel-table');
    }
}
