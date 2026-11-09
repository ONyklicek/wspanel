<?php

namespace App\Livewire\Pages;

use App\Livewire\Concerns\TranslatesPageText;
use App\Models\Setting;
use App\Services\Notify\Heartbeat;
use App\Support\PanelMail;
use App\Support\Toast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Radio;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireForms\Components\Toggle;
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

    /** @return array<string, mixed>|null the external SMTP server (no password) */
    public function smtp(): ?array
    {
        $s = PanelMail::get();

        return $s === null ? null : array_diff_key($s, ['password' => true]) + ['has_password' => ($s['password'] ?? '') !== ''];
    }

    public function testMail(): void
    {
        if (! $this->isAdmin()) {
            return;
        }
        $to = (string) auth()->user()?->email;
        try {
            Mail::raw(__('Zkušební e-mail z WSPanelu: odesílání funguje.')."\n\n— WSPanel ".config('app.url'), fn ($m) => $m->to($to)->subject('[WSPanel] '.__('Zkušební e-mail')));
            Toast::success(__('Zkušební e-mail odeslán na :to', ['to' => $to]), PanelMail::enabled() ? __('Přes :host.', ['host' => PanelMail::get()['host']]) : __('Přes poštu tohoto serveru.'));
        } catch (\Throwable $e) {
            Toast::error(__('E-mail se nepodařilo odeslat'), $e->getMessage());
        }
    }

    protected function headerActions(): array
    {
        return [
            Action::make('smtp')
                ->label(__('Odesílání e-mailů'))
                ->icon('outline:envelope')
                ->color('gray')
                ->visible(fn () => $this->isAdmin())
                ->modalDescription(__('E-maily panelu (notifikace, obnova hesla) odchází poštou tohoto serveru. Když server ven poštu neposílá (blokovaný port 25, chybějící PTR), nastavte externí SMTP server: Seznam, Gmail, Microsoft 365, SendGrid, Mailgun, Amazon SES… Heslo se uloží šifrovaně a už se nezobrazí.'))
                ->fillFormUsing(fn () => ($s = PanelMail::get()) === null
                    ? ['enabled' => true, 'port' => 587, 'encryption' => 'tls', 'from_name' => 'WSPanel', 'from_address' => config('mail.from.address')]
                    : ['enabled' => $s['enabled'], 'host' => $s['host'], 'port' => $s['port'], 'encryption' => $s['encryption'], 'username' => $s['username'], 'from_address' => $s['from_address'], 'from_name' => $s['from_name']])
                ->form([
                    Toggle::make('enabled')->label(__('Posílat přes externí SMTP server'))->live(),
                    TextInput::make('host')->label(__('Server'))->placeholder('smtp.seznam.cz')->requiredIf('enabled', true)->maxLength(255)
                        ->rules(['nullable', 'regex:/^[A-Za-z0-9.-]+$/'])->visibleWhen('enabled', true),
                    TextInput::make('port')->label(__('Port'))->integer()->requiredIf('enabled', true)->rules(['nullable', 'integer', 'between:1,65535'])->visibleWhen('enabled', true),
                    Radio::make('encryption')->label(__('Šifrování'))->options(['tls' => __('STARTTLS (obvykle port 587)'), 'ssl' => __('SSL/TLS (obvykle port 465)'), 'none' => __('žádné (jen důvěryhodná síť)')])
                        ->requiredIf('enabled', true)->rules(['nullable', 'in:tls,ssl,none'])->visibleWhen('enabled', true),
                    TextInput::make('username')->label(__('Uživatel'))->maxLength(255)->rules(['nullable', 'not_regex:/[\r\n]/'])->visibleWhen('enabled', true),
                    TextInput::make('password')->label(__('Heslo'))->password()->revealable()->maxLength(500)
                        ->helperText(fn () => (PanelMail::get()['password'] ?? '') !== '' ? __('Prázdné = ponechat uložené heslo.') : null)
                        ->rules(['nullable', 'not_regex:/[\r\n]/'])->visibleWhen('enabled', true),
                    TextInput::make('from_address')->label(__('Odesílatel (adresa)'))->email()->requiredIf('enabled', true)->rules(['nullable', 'email'])->visibleWhen('enabled', true)
                        ->helperText(__('Musí ji SMTP server dovolit (obvykle adresa účtu nebo ověřené domény).')),
                    TextInput::make('from_name')->label(__('Odesílatel (jméno)'))->maxLength(100)->rules(['nullable', 'not_regex:/[\r\n]/'])->visibleWhen('enabled', true),
                ])
                ->action(function (array $data) {
                    if (! ($data['enabled'] ?? false)) {
                        PanelMail::disable();
                        Toast::success(__('E-maily jdou zase poštou tohoto serveru'));

                        return;
                    }
                    PanelMail::save($data);
                    PanelMail::apply();
                    $this->testMail();
                }),
            Action::make('testMail')
                ->label(__('Zkušební e-mail'))
                ->icon('outline:paper-airplane')
                ->color('gray')
                ->visible(fn () => $this->isAdmin())
                ->action(fn () => $this->testMail()),
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
