<?php

namespace App\Livewire\Sites;

use App\Actions\Sites\ManageDeploys;
use App\Actions\Sites\ManagePhpSettings;
use App\Enums\Framework;
use App\Enums\SiteMode;
use App\Enums\SshAccess;
use App\Models\Database;
use App\Models\Deployment;
use App\Models\DnsZone;
use App\Models\Site;
use App\Wire\Resources\SiteResource;
use App\Wire\Sites\SiteActions;
use Illuminate\Contracts\View\View;
use NyonCode\WireCore\Actions\ActionGroup;
use NyonCode\WireCore\Foundation\Schema\Section;
use NyonCode\WireCore\Infolists\Components\TextEntry;
use NyonCode\WireCore\Infolists\Infolist;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

/**
 * A site at a glance: what it is, how to reach it, and below it what runs
 * for it (processes) and what it wrote (logs).
 */
class ViewSite extends ViewPage
{
    protected static ?string $resource = SiteResource::class;

    public function mount(mixed $record = null): void
    {
        parent::mount($record);
        // Fresh deploy state for the overview (the agent is the source of truth).
        $site = $this->nativeRecord();
        if ($site instanceof Site) {
            rescue(fn () => app(ManageDeploys::class)->history($site), report: false);
        }
    }

    public function getTitle(): ?string
    {
        $site = $this->nativeRecord();

        return $site instanceof Site ? $site->domain : 'Web';
    }

    protected function headerActions(): array
    {
        return [
            SiteActions::open(),
            SiteActions::deploy(),
            ActionGroup::make(SiteActions::more())->label('Další')->icon('outline:ellipsis-vertical'),
        ];
    }

    public function infolist(): Infolist
    {
        /** @var Site $site */
        $site = $this->nativeRecord();
        $zone = DnsZone::query()->where('domain', $site->domain)->first();
        $deploy = Deployment::query()->where('site_id', $site->id)->latest('started_at')->first();
        $port = config('panel.ssh_port');

        return Infolist::make()->record($site)->livewireComponent($this)->schema([
            Section::make('web')->label('Web')->icon('outline:globe-alt')->columns(2)->schema([
                TextEntry::make('url')->label('Adresa')->state(fn () => $site->url())->copyable(),
                TextEntry::make('state')->label('Stav')->state(fn () => $site->stateLabel())->badge()->color($site->stateColor())
                    ->helperText(match (true) {
                        ! $site->enabled => 'Návštěvníci vidí stránku „Web je dočasně vypnutý“.',
                        $site->health_checked_at === null => 'Zatím neověřeno.',
                        default => 'HTTP '.($site->health_status ?? '—').' · ověřeno '.$site->health_checked_at->diffForHumans()
                            .($site->health_error ? ' · '.$site->health_error : ''),
                    }),
                TextEntry::make('runtime')->label('Běží na')->state(fn () => $site->runtimeLabel()
                    .($site->mode === SiteMode::Node && $site->port ? " · 127.0.0.1:{$site->port}" : '')),
                TextEntry::make('framework')->label('Typ aplikace')->state(fn () => $site->framework->getLabel())->badge()->color('gray'),
                TextEntry::make('system_user')->label('Systémový uživatel')->copyable(),
                TextEntry::make('home')->label('Adresář webu')->copyable(),
                TextEntry::make('doc_root')->label('Kořen webu')->copyable()
                    ->helperText($site->framework === Framework::Php
                        ? 'Přes SFTP nahrávejte do public_html/ (vede sem).'
                        : 'current/ ukazuje na aktivní release z posledního deploye.'),
                TextEntry::make('tls')->label('HTTPS')->state(fn () => match ($site->tls_mode) {
                    'internal' => 'interní certifikační autorita',
                    'custom' => 'vlastní certifikát',
                    default => "Let's Encrypt",
                }),
                TextEntry::make('edge_domains')->label('Další domény')
                    ->state(fn () => implode(', ', array_merge($site->edge_settings['aliases'] ?? [], array_map(fn ($d) => $d.' → '.$site->domain, $site->edge_settings['redirect_hosts'] ?? []))) ?: 'žádné (Další → Domény a přístup)'),
                TextEntry::make('edge_protection')->label('Ochrana')
                    ->state(fn () => collect([
                        ($site->edge_settings['auth_path'] ?? '') !== '' ? 'heslo na '.$site->edge_settings['auth_path'] : null,
                        ($site->edge_settings['allow_ips'] ?? []) !== [] ? 'jen z '.count($site->edge_settings['allow_ips']).' IP na '.($site->edge_settings['allow_path'] ?? '/') : null,
                        ($site->edge_settings['hsts'] ?? false) ? 'HSTS' : null,
                    ])->filter()->join(' · ') ?: 'veřejný web'),
                TextEntry::make('php_settings')->label('PHP nastavení')
                    ->state(fn () => ManagePhpSettings::summary($site))
                    ->visible($site->mode !== SiteMode::Node),
            ]),
            Section::make('access')->label('Přístup a služby')->icon('outline:key')->columns(2)->schema([
                TextEntry::make('ssh_access')->label('SSH / SFTP')->state(fn () => $site->ssh_access->getLabel())->badge()->color($site->ssh_access->getColor()),
                TextEntry::make('sftp')->label('Připojení')
                    ->state(fn () => $site->ssh_access === SshAccess::Off ? 'zapnete v menu Další → SSH / SFTP' : "sftp -P {$port} {$site->sshTarget()}")
                    ->copyable($site->ssh_access !== SshAccess::Off),
                TextEntry::make('databases')->label('Databáze')
                    ->state(fn () => $site->databases->map(fn (Database $d) => $d->name.' ('.$d->engine->getLabel().')')->join(', ') ?: 'žádná'),
                TextEntry::make('mail')->label('Pošta')
                    ->state(fn () => $site->mail_mode === 'capture' ? 'zachytává se v Mailpitu' : 'odesílá se')
                    ->badge()->color($site->mail_mode === 'capture' ? 'warning' : 'success'),
                TextEntry::make('dns')->label('DNS')
                    ->state(fn () => $zone ? 'zóna: '.$zone->provider->getLabel() : 'zóna není v panelu (spravuje ji registrátor)'),
                TextEntry::make('deploy')->label('Poslední deploy')
                    ->state(fn () => $deploy ? $deploy->statusLabel().' · '.$deploy->started_at?->diffForHumans() : 'zatím žádný'),
            ]),
        ]);
    }

    public function render(): View
    {
        $record = $this->nativeRecord();

        return view('livewire.sites.view-site', [
            'title' => $this->getTitle(),
            'breadcrumbs' => $this->breadcrumbs(),
            'headerActions' => $this->renderedHeaderActions(),
            'subNavigation' => $this->subNavigation($record),
            'site' => $record,
            'infolist' => $this->infolist(),
        ]);
    }
}
