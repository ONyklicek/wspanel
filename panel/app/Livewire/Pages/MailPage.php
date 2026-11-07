<?php

namespace App\Livewire\Pages;

use App\Actions\Mail\ManageMail;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Forms\Options;
use Illuminate\Support\Collection;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Display\Placeholder;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WirePanels\Pages\Page;

/**
 * Outgoing mail of the sites: Postfix + DKIM, or capture in Mailpit.
 */
class MailPage extends Page
{
    protected static string $view = 'livewire.pages.mail';

    /** Server-wide settings: administrators only. */
    protected static ?string $permission = 'admin';

    protected ?string $title = 'Odchozí pošta';

    protected static ?string $slug = 'mail';

    protected static ?string $navigationLabel = 'Pošta';

    protected static ?string $navigationIcon = 'outline:envelope';

    protected static ?string $navigationGroup = 'network';

    protected static int $navigationSort = 30;

    /** @var array<string, mixed> */
    public array $status = [];

    public ?string $error = null;

    public string $log = '';

    public function mount(): void
    {
        $this->refreshStatus();
    }

    public function refreshStatus(): void
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
            Toast::success($mode === 'capture' ? "{$site->domain}: pošta se zachytává v Mailpitu" : "{$site->domain}: pošta se odesílá", 'Proces aplikace a workery načtou změnu po restartu webu.');
        } catch (AgentException $e) {
            Toast::error('Změna selhala', $e->getMessage());
        }
        $this->refreshStatus();
    }

    public function createDkim(string $domain): void
    {
        abort_unless(Site::query()->where('domain', $domain)->exists(), 404);
        try {
            app(ManageMail::class)->dkim($domain);
            Toast::success("DKIM klíč pro {$domain} vytvořen", 'Přidejte TXT záznam z tabulky do DNS domény.');
        } catch (AgentException $e) {
            Toast::error('DKIM se nepodařilo vytvořit', $e->getMessage());
        }
        $this->refreshStatus();
    }

    public function loadLog(): void
    {
        try {
            $this->log = app(ManageMail::class)->log();
        } catch (AgentException $e) {
            $this->log = 'Log se nepodařilo načíst: '.$e->getMessage();
        }
    }

    protected function headerActions(): array
    {
        return [
            Action::make('mailpit')
                ->label('Otevřít Mailpit')
                ->icon('outline:inbox-stack')
                ->url((string) config('panel.mailpit_path'), true),
            Action::make('relay')
                ->label('Relay (SMTP poskytovatel)')
                ->icon('outline:server-stack')
                ->color('gray')
                ->modalDescription('Bez relaye doručuje Postfix poštu přímo na servery příjemců. To vyžaduje čistou IP adresu s reverzním záznamem (PTR) a otevřený port 25 (mnoho cloudů ho blokuje). Spolehlivější je poslat poštu přes poskytovatele jako Mailgun, Amazon SES, Postmark, Brevo nebo SMTP vašeho hostingu.')
                ->fillFormUsing(function () {
                    $relay = (string) ($this->status['relay'] ?? '');
                    preg_match('/^\[(.+)\]:(\d+)$/', $relay, $m);

                    return ['host' => $m[1] ?? '', 'port' => (int) ($m[2] ?? 587)];
                })
                ->form([
                    TextInput::make('host')->label('SMTP server')->placeholder('smtp.eu.mailgun.org')->helperText('Prázdné = doručovat přímo.'),
                    Select::make('port')->rules([Options::rule()])->label('Port')->options([587 => '587 (STARTTLS)', 465 => '465 (TLS)', 2525 => '2525', 25 => '25'])->default(587)
                        ->hiddenWhen('host', [null, '']),
                    TextInput::make('username')->label('Uživatel')->hiddenWhen('host', [null, '']),
                    TextInput::make('password')->label('Heslo / API klíč')->password()->revealable()->hiddenWhen('host', [null, ''])
                        ->helperText('Uloží se jen na serveru (root, 0600), panel ho neuchovává.'),
                    Placeholder::make('tls')->hiddenLabel()->content('Spojení s relayem je vždy šifrované (TLS).'),
                ])
                ->action(function (array $data) {
                    try {
                        app(ManageMail::class)->setRelay($data['host'] ?: null, (int) ($data['port'] ?? 587) ?: null, $data['username'] ?? null, $data['password'] ?? null);
                        Toast::success($data['host'] ? "Pošta jde přes {$data['host']}" : 'Pošta se doručuje přímo');
                    } catch (AgentException $e) {
                        Toast::error('Uložení selhalo', $e->getMessage());
                    }
                    $this->refreshStatus();
                }),
        ];
    }
}
