<?php

namespace App\Livewire;

use App\Enums\NotificationChannelType;
use App\Models\NotificationChannel;
use App\Services\Notify\Notifier;
use App\Support\Lang;
use App\Support\Toast;
use App\Wire\Forms\Options;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use NyonCode\WireCore\Actions\Action;
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
     * @return array{address?: string, url?: string, token?: string, chat_id?: string, topic?: string}
     */
    public static function config(array $data): array
    {
        $type = NotificationChannelType::from((string) $data['type']);

        return match ($type) {
            NotificationChannelType::Email => ['address' => trim((string) $data['address'])],
            NotificationChannelType::Telegram => ['token' => trim((string) $data['token']), 'chat_id' => trim((string) $data['chat_id'])],
            NotificationChannelType::Ntfy => array_filter(['topic' => trim((string) $data['topic']), 'token' => trim((string) ($data['ntfy_token'] ?? ''))]),
            default => (function () use ($type, $data) {
                $url = trim((string) $data['url']);
                if (! preg_match((string) $type->webhookPattern(), $url)) {
                    throw ValidationException::withMessages(['url' => 'Adresa webhooku '.$type->getLabel().__(' má jiný tvar.')]);
                }

                return ['url' => $url];
            })(),
        };
    }

    /** @return list<Field> */
    private static function fields(): array
    {
        return [
            Select::make('type')->label(__('Kam'))->options(NotificationChannelType::class)->rules([Options::rule(NotificationChannelType::class)])->default('email')->required()->live(),
            TextInput::make('name')->label(__('Název'))->required()->rules(['string', 'max:100']),
            TextInput::make('address')->label(__('E-mail'))->email()->visibleWhen('type', 'email')->requiredIf('type', 'email')->rules(['nullable', 'email:rfc', 'max:200']),
            TextInput::make('url')->label(__('Webhook URL'))->visibleWhen('type', ['slack', 'discord'])->requiredIf('type', ['slack', 'discord'])
                ->helperText(__('Slack: Incoming Webhook (https://hooks.slack.com/…), Discord: Integrace → Webhook.'))->rules(['nullable', 'url', 'max:300']),
            TextInput::make('token')->label(__('Token bota'))->password()->revealable()->visibleWhen('type', 'telegram')->requiredIf('type', 'telegram')
                ->helperText(__('Od @BotFather.'))->rules(['nullable', 'regex:/^[0-9]{5,15}:[A-Za-z0-9_-]{20,60}$/']),
            TextInput::make('chat_id')->label(__('Chat ID'))->visibleWhen('type', 'telegram')->requiredIf('type', 'telegram')->rules(['nullable', 'regex:/^-?[0-9]{3,20}$|^@[A-Za-z0-9_]{5,40}$/']),
            TextInput::make('topic')->label(__('Téma (topic) na ntfy.sh'))->visibleWhen('type', 'ntfy')->requiredIf('type', 'ntfy')
                ->helperText(__('Kdo zná téma, čte zprávy: zvolte dlouhé náhodné (např. wspanel-7f3k9q2m…) a odebírejte ho v aplikaci ntfy.'))
                ->rules(['nullable', 'regex:/^[A-Za-z0-9_-]{12,64}$/']),
            TextInput::make('ntfy_token')->label(__('Přístupový token (nepovinné)'))->password()->revealable()->visibleWhen('type', 'ntfy')
                ->helperText(__('Pro rezervované téma účtu ntfy.sh (tk_…).'))->rules(['nullable', 'regex:/^tk_[A-Za-z0-9]{20,60}$/']),
            CheckboxList::make('events')->label(__('Posílat'))->options(Lang::values(NotificationChannel::EVENTS))->default(['problems', 'resolved'])->required(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->query(NotificationChannel::query())
            ->columns([
                TextColumn::make('name')->label(__('Název'))->weight('medium'),
                TextColumn::make('type')->label(__('Kam'))->formatStateUsing(fn ($state) => $state instanceof NotificationChannelType ? $state->getLabel() : $state),
                TextColumn::make('events')->label(__('Posílá'))->state(fn (NotificationChannel $r) => collect($r->events ?? [])->map(fn ($e) => __(NotificationChannel::EVENTS[$e] ?? $e))->join(', ')),
                BadgeColumn::make('state')->label(__('Stav'))
                    ->state(fn (NotificationChannel $r) => ! $r->enabled ? 'vypnuto' : ($r->last_error ? 'chyba' : __('aktivní')))
                    ->colorUsing(fn ($state) => [__('aktivní') => 'success', 'chyba' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('last_error')->label(__('Poslední chyba'))->limit(80)->placeholder('—'),
            ])
            ->headerActions([
                Action::make('create')->label(__('Přidat kanál'))->icon('outline:plus')
                    ->form(self::fields())
                    ->action(function (array $data) {
                        NotificationChannel::create(['type' => $data['type'], 'name' => $data['name'], 'config' => self::config($data),
                            'events' => array_values($data['events'] ?? []), 'enabled' => true]);
                        Toast::success(__('Kanál přidán'), __('Vyzkoušejte ho tlačítkem Zkusit.'));
                    }),
            ])
            ->actions([
                Action::make('test')->label(__('Zkusit'))->icon('outline:paper-airplane')->color('gray')
                    ->action(function (NotificationChannel $record) {
                        if (RateLimiter::tooManyAttempts('notify-test:'.$record->id, 3)) {
                            Toast::warning(__('Zkuste to za chvíli'));

                            return;
                        }
                        RateLimiter::hit('notify-test:'.$record->id, 60);
                        app(Notifier::class)->send($record, __('Zkušební zpráva z WSPanelu'), __('Takhle budou vypadat upozornění.'))
                            ? Toast::success(__('Odesláno'))
                            : Toast::error(__('Odeslání selhalo'), (string) $record->fresh()->last_error);
                    }),
                Action::make('edit')->label(__('Upravit'))->icon('outline:pencil-square')
                    ->fillFormUsing(fn (NotificationChannel $r) => ['name' => $r->name, 'events' => $r->events, 'enabled' => $r->enabled])
                    ->form([
                        TextInput::make('name')->label(__('Název'))->required()->rules(['string', 'max:100']),
                        CheckboxList::make('events')->label(__('Posílat'))->options(Lang::values(NotificationChannel::EVENTS))->required(),
                        Toggle::make('enabled')->label(__('Zapnuto')),
                    ])
                    ->action(function (NotificationChannel $record, array $data) {
                        $record->update(['name' => $data['name'], 'events' => array_values($data['events'] ?? []), 'enabled' => (bool) $data['enabled']]);
                        Toast::success(__('Uloženo'));
                    }),
                Action::make('delete')->label(__('Smazat'))->icon('outline:trash')->color('danger')->requiresConfirmation()
                    ->action(function (NotificationChannel $record) {
                        $record->delete();
                        Toast::success(__('Kanál smazán'));
                    }),
            ])
            ->emptyState(__('Zatím žádný kanál'), __('Přidejte e-mail, Slack, Discord, Telegram nebo ntfy.'), 'outline:bell')
            ->searchable(false)
            ->paginated(false);
    }

    public function render(): View
    {
        return view('livewire.notification-channel-table');
    }
}
