<?php

namespace App\Filament\Pages;

use App\Actions\Mail\ManageMail;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\Agent\AgentException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Outgoing mail of the sites: Postfix + DKIM, or capture in Mailpit.
 */
class Mail extends Page
{
    protected string $view = 'filament.pages.mail';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'Pošta';

    protected static ?string $title = 'Odchozí pošta';

    protected static ?int $navigationSort = 5;

    /** @var array<string, mixed> */
    public array $status = [];

    public ?string $error = null;

    public string $log = '';

    public function mount(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        try {
            $this->status = app(ManageMail::class)->status();
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** @return Collection<int, Site> */
    public function sites(): Collection
    {
        return Site::query()->where('status', SiteStatus::Active)->orderBy('domain')->get();
    }

    /** @return array<string, array{domain: string, selector: string, name: string, value: string}> */
    public function dkimKeys(): array
    {
        return collect($this->status['dkim'] ?? [])->keyBy('domain')->all();
    }

    /** @return list<array{type: string, name: string, value: string, note: string}> */
    public function dnsRecords(string $domain): array
    {
        return ManageMail::dnsRecords($domain, $this->dkimKeys()[$domain] ?? null, $this->status['relay'] ?? null, config('panel.public_ipv4'));
    }

    public function serviceState(string $unit): string
    {
        return collect($this->status['services'] ?? [])->firstWhere('unit', $unit)['active'] ?? 'unknown';
    }

    public function setMode(int $siteId, string $mode): void
    {
        $site = Site::findOrFail($siteId);
        abort_unless(in_array($mode, ['send', 'capture'], true), 400);
        try {
            app(ManageMail::class)->setSiteMode($site, $mode);
            Notification::make()
                ->title($mode === 'capture' ? "{$site->domain}: pošta se zachytává v Mailpitu" : "{$site->domain}: pošta se odesílá")
                ->body('Proces aplikace a workery načtou změnu po restartu webu.')
                ->success()->send();
        } catch (AgentException $e) {
            Notification::make()->title('Změna selhala')->body($e->getMessage())->danger()->persistent()->send();
        }
        $this->refresh();
    }

    public function createDkim(string $domain): void
    {
        abort_unless(Site::query()->where('domain', $domain)->exists(), 404);
        try {
            app(ManageMail::class)->dkim($domain);
            Notification::make()->title("DKIM klíč pro {$domain} vytvořen")->body('Přidejte TXT záznam z tabulky do DNS domény.')->success()->send();
        } catch (AgentException $e) {
            Notification::make()->title('DKIM se nepodařilo vytvořit')->body($e->getMessage())->danger()->persistent()->send();
        }
        $this->refresh();
    }

    public function loadLog(): void
    {
        try {
            $this->log = app(ManageMail::class)->log();
        } catch (AgentException $e) {
            $this->log = 'Log se nepodařilo načíst: '.$e->getMessage();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('mailpit')
                ->label('Otevřít Mailpit')
                ->icon(Heroicon::OutlinedInboxStack)
                ->url(config('panel.mailpit_path'), shouldOpenInNewTab: true),
            Action::make('relay')
                ->label('Relay (SMTP poskytovatel)')
                ->icon(Heroicon::OutlinedServerStack)
                ->color('gray')
                ->modalDescription('Bez relaye doručuje Postfix poštu přímo na servery příjemců. To vyžaduje čistou IP adresu s reverzním záznamem (PTR) a otevřený port 25 (mnoho cloudů ho blokuje). Spolehlivější je poslat poštu přes poskytovatele jako Mailgun, Amazon SES, Postmark, Brevo nebo SMTP vašeho hostingu.')
                ->fillForm(function () {
                    $relay = (string) ($this->status['relay'] ?? '');
                    preg_match('/^\[(.+)\]:(\d+)$/', $relay, $m);

                    return ['host' => $m[1] ?? '', 'port' => (int) ($m[2] ?? 587)];
                })
                ->schema([
                    TextInput::make('host')->label('SMTP server')->placeholder('smtp.eu.mailgun.org')->helperText('Prázdné = doručovat přímo.'),
                    Select::make('port')->label('Port')->options([587 => '587 (STARTTLS)', 465 => '465 (TLS)', 2525 => '2525', 25 => '25'])->default(587)
                        ->visible(fn (Get $get) => filled($get('host'))),
                    TextInput::make('username')->label('Uživatel')->visible(fn (Get $get) => filled($get('host'))),
                    TextInput::make('password')->label('Heslo / API klíč')->password()->revealable()->visible(fn (Get $get) => filled($get('host')))
                        ->helperText('Uloží se jen na serveru (root, 0600), panel ho neuchovává.'),
                    Text::make('Spojení s relayem je vždy šifrované (TLS).'),
                ])
                ->action(function (array $data) {
                    try {
                        app(ManageMail::class)->setRelay($data['host'] ?: null, (int) ($data['port'] ?? 587) ?: null, $data['username'] ?? null, $data['password'] ?? null);
                        Notification::make()->title($data['host'] ? "Pošta jde přes {$data['host']}" : 'Pošta se doručuje přímo')->success()->send();
                    } catch (AgentException $e) {
                        Notification::make()->title('Uložení selhalo')->body($e->getMessage())->danger()->persistent()->send();
                    }
                    $this->refresh();
                }),
        ];
    }
}
