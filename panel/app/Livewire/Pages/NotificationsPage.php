<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\TranslatesPageText;
use App\Models\Setting;
use App\Services\Notify\Heartbeat;
use App\Support\Toast;
use Illuminate\Support\Facades\Gate;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WirePanels\Pages\Page;

/** Where the signed-in user is told about problems and deploys. */
class NotificationsPage extends Page
{
    use TranslatesPageText;

    protected static string $view = 'livewire.pages.notifications';

    protected ?string $title = 'Notifikace';

    protected static ?string $slug = 'notification-channels';

    protected static ?string $navigationLabel = 'Notifikace';

    protected static ?string $navigationIcon = 'outline:bell-alert';

    protected static ?string $navigationGroup = 'account';

    protected static int $navigationSort = 10;

    /** "notifications" is the history of wire-module-notifications. */
    public static function key(): string
    {
        return 'notification-channels';
    }

    public function isAdmin(): bool
    {
        return Gate::allows('admin');
    }

    /** @return array{url: ?string, last: array<string, mixed>|null} */
    public function heartbeat(): array
    {
        return ['url' => Heartbeat::url(), 'last' => Setting::get('heartbeat.last')];
    }

    protected function headerActions(): array
    {
        return [
            Action::make('heartbeat')
                ->label(__('Hlídání serveru zvenku'))
                ->icon('outline:heart')
                ->color('gray')
                ->visible(fn () => $this->isAdmin())
                ->modalDescription(__('Když spadne celý server, panel to oznámit nemůže. Healthchecks.io (zdarma) to pozná za něj: panel mu každých pět minut dá vědět, že běží (při vážném problému pošle i jaký), a když se odmlčí, Healthchecks.io pošle upozornění e-mailem, SMS, do Slacku…'))
                ->fillFormUsing(fn () => ['url' => Heartbeat::url()])
                ->form([
                    TextInput::make('url')->label(__('Ping URL kontroly'))
                        ->placeholder('https://hc-ping.com/…')
                        ->helperText(__('Na healthchecks.io založte kontrolu s periodou 5 minut a vložte její Ping URL. Prázdné = vypnuto.'))
                        ->rules(['nullable', 'regex:'.Heartbeat::URL_PATTERN]),
                ])
                ->action(function (array $data) {
                    $url = trim((string) ($data['url'] ?? ''));
                    Heartbeat::configure($url === '' ? null : $url);
                    if ($url === '') {
                        Toast::success(__('Hlídání zvenku vypnuto'));

                        return;
                    }
                    $result = app(Heartbeat::class)->ping();
                    $result['ok']
                        ? Toast::success(__('Hlídání zvenku zapnuto'), __('První signál odešel; Healthchecks.io ho už ukazuje.'))
                        : Toast::error(__('Signál se nepodařilo poslat'), (string) (Setting::get('heartbeat.last')['error'] ?? ''));
                }),
        ];
    }
}
