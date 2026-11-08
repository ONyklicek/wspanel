<?php

namespace App\Livewire\Pages;

use App\Actions\Mail\ManageMail;
use App\Enums\SiteStatus;
use App\Livewire\Concerns\TranslatesPageText;
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
    use TranslatesPageText;

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

    /** @var list<array<string, mixed>>|null the queue, once loaded */
    public ?array $queue = null;

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
            Toast::success($mode === 'capture' ? __(':domain: pošta se zachytává v Mailpitu', ['domain' => $site->domain]) : __(':domain: pošta se odesílá', ['domain' => $site->domain]), __('Proces aplikace a workery načtou změnu po restartu webu.'));
        } catch (AgentException $e) {
            Toast::error(__('Změna selhala'), $e->getMessage());
        }
        $this->refreshStatus();
    }

    public function createDkim(string $domain): void
    {
        abort_unless(Site::query()->where('domain', $domain)->exists(), 404);
        try {
            app(ManageMail::class)->dkim($domain);
            Toast::success(__('DKIM klíč pro :domain vytvořen', ['domain' => $domain]), __('Přidejte TXT záznam z tabulky do DNS domény.'));
        } catch (AgentException $e) {
            Toast::error(__('DKIM se nepodařilo vytvořit'), $e->getMessage());
        }
        $this->refreshStatus();
    }

    public function loadQueue(): void
    {
        try {
            $this->queue = app(ManageMail::class)->queue();
        } catch (AgentException $e) {
            $this->queue = null;
            Toast::error(__('Frontu se nepodařilo načíst'), $e->getMessage());
        }
    }

    public function flushQueue(): void
    {
        try {
            app(ManageMail::class)->flushQueue();
            Toast::success(__('Fronta se odesílá znovu'));
        } catch (AgentException $e) {
            Toast::error(__('Odeslání selhalo'), $e->getMessage());
        }
        $this->loadQueue();
        $this->refreshStatus();
    }

    public function deleteQueued(?string $id = null): void
    {
        try {
            app(ManageMail::class)->deleteQueued($id === null ? null : [$id]);
            Toast::success($id === null ? __('Fronta smazána') : __('Zpráva smazána z fronty'));
        } catch (AgentException $e) {
            Toast::error(__('Smazání selhalo'), $e->getMessage());
        }
        $this->loadQueue();
        $this->refreshStatus();
    }

    public function loadLog(): void
    {
        try {
            $this->log = app(ManageMail::class)->log();
        } catch (AgentException $e) {
            $this->log = __('Log se nepodařilo načíst: ').$e->getMessage();
        }
    }

    protected function headerActions(): array
    {
        return [
            Action::make('mailpit')
                ->label(__('Otevřít Mailpit'))
                ->icon('outline:inbox-stack')
                ->url((string) config('panel.mailpit_path'), true),
            Action::make('relay')
                ->label(__('Relay (SMTP poskytovatel)'))
                ->icon('outline:server-stack')
                ->color('gray')
                ->modalDescription(__('Bez relaye doručuje Postfix poštu přímo na servery příjemců. To vyžaduje čistou IP adresu s reverzním záznamem (PTR) a otevřený port 25 (mnoho cloudů ho blokuje). Spolehlivější je poslat poštu přes poskytovatele jako Mailgun, Amazon SES, Postmark, Brevo nebo SMTP vašeho hostingu.'))
                ->fillFormUsing(function () {
                    $relay = (string) ($this->status['relay'] ?? '');
                    preg_match('/^\[(.+)\]:(\d+)$/', $relay, $m);

                    return ['host' => $m[1] ?? '', 'port' => (int) ($m[2] ?? 587)];
                })
                ->form([
                    TextInput::make('host')->label(__('SMTP server'))->placeholder('smtp.eu.mailgun.org')->helperText(__('Prázdné = doručovat přímo.')),
                    Select::make('port')->rules([Options::rule()])->label(__('Port'))->options([587 => '587 (STARTTLS)', 465 => '465 (TLS)', 2525 => '2525', 25 => '25'])->default(587)
                        ->hiddenWhen('host', [null, '']),
                    TextInput::make('username')->label(__('Uživatel'))->hiddenWhen('host', [null, '']),
                    TextInput::make('password')->label(__('Heslo / API klíč'))->password()->revealable()->hiddenWhen('host', [null, ''])
                        ->helperText(__('Uloží se jen na serveru (root, 0600), panel ho neuchovává.')),
                    Placeholder::make('tls')->hiddenLabel()->content(__('Spojení s relayem je vždy šifrované (TLS).')),
                ])
                ->action(function (array $data) {
                    try {
                        app(ManageMail::class)->setRelay($data['host'] ?: null, (int) ($data['port'] ?? 587) ?: null, $data['username'] ?? null, $data['password'] ?? null);
                        Toast::success($data['host'] ? __('Pošta jde přes :host', ['host' => $data['host']]) : __('Pošta se doručuje přímo'));
                    } catch (AgentException $e) {
                        Toast::error(__('Uložení selhalo'), $e->getMessage());
                    }
                    $this->refreshStatus();
                }),
        ];
    }
}
