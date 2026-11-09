<?php

namespace App\Livewire\Sites;

use App\Actions\Dns\ManageSiteDns;
use App\Actions\Sites\ManageDeploys;
use App\Actions\Sites\ManagePhpSettings;
use App\Actions\Sites\ManageValkey;
use App\Actions\Sites\ManageXhprof;
use App\Enums\Framework;
use App\Enums\SshAccess;
use App\Models\Database;
use App\Models\Deployment;
use App\Models\MailDomain;
use App\Models\Site;
use App\Wire\Resources\DatabaseResource;
use App\Wire\Resources\SiteResource;
use App\Wire\Sites\SiteActions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use NyonCode\WireCore\Actions\Action;
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

        return $site instanceof Site ? $site->domain : __('Web');
    }

    protected function headerActions(): array
    {
        return [
            SiteActions::open(),
            SiteActions::credentials(),
            SiteActions::deploy(),
            ActionGroup::make(SiteActions::quick())->label(__('Další'))->icon('outline:ellipsis-vertical'),
        ];
    }

    public function infolist(): Infolist
    {
        /** @var Site $site */
        $site = $this->nativeRecord();
        $zone = ManageSiteDns::zoneFor($site->domain);
        $mailDomain = MailDomain::query()->where('domain', SiteMail::mailDomainName($site))->first();
        $deploy = Deployment::query()->where('site_id', $site->id)->latest('started_at')->first();
        $port = config('panel.ssh_port');

        return Infolist::make()->record($site)->livewireComponent($this)->schema([
            Section::make('web')->label(__('Web'))->icon('outline:globe-alt')->columns(2)->schema([
                TextEntry::make('url')->label(__('Adresa'))->state(fn () => $site->url())->copyable(),
                TextEntry::make('state')->label(__('Stav'))->state(fn () => $site->stateLabel())->badge()->color($site->stateColor())
                    ->helperText(match (true) {
                        ! $site->enabled => __('Návštěvníci vidí stránku „Web je dočasně vypnutý“.'),
                        $site->health_checked_at === null => __('Zatím neověřeno.'),
                        default => 'HTTP '.($site->health_status ?? '—').__(' · ověřeno ').$site->health_checked_at->diffForHumans()
                            .($site->health_error ? ' · '.$site->health_error : ''),
                    }),
                TextEntry::make('runtime')->label(__('Běží na'))->state(fn () => $site->runtimeLabel()
                    .($site->mode->isApp() && $site->port ? " · 127.0.0.1:{$site->port}" : '')),
                TextEntry::make('framework')->label(__('Typ aplikace'))->state(fn () => $site->framework->getLabel())->badge()->color('gray'),
                TextEntry::make('system_user')->label(__('Systémový uživatel'))->copyable(),
                TextEntry::make('home')->label(__('Adresář webu'))->copyable(),
                TextEntry::make('doc_root')->label(__('Kořen webu'))->copyable()
                    ->helperText($site->framework === Framework::Php
                        ? __('Přes SFTP nahrávejte do public_html/ (vede sem).')
                        : __('current/ ukazuje na aktivní release z posledního deploye.')),
                TextEntry::make('tls')->label('HTTPS')->state(fn () => match ($site->tls_mode) {
                    'internal' => __('interní certifikační autorita'),
                    'custom' => __('vlastní certifikát'),
                    default => "Let's Encrypt",
                }),
                TextEntry::make('edge_domains')->label(__('Další domény'))
                    ->state(fn () => implode(', ', array_merge($site->edge_settings['aliases'] ?? [], array_map(fn ($d) => $d.' → '.$site->domain, $site->edge_settings['redirect_hosts'] ?? []))) ?: __('žádné (Nastavení → Domény a HTTPS)')),
                TextEntry::make('edge_protection')->label(__('Ochrana'))
                    ->state(fn () => collect([
                        ($site->edge_settings['auth_path'] ?? '') !== '' ? 'heslo na '.$site->edge_settings['auth_path'] : null,
                        ($site->edge_settings['allow_ips'] ?? []) !== [] ? 'jen z '.count($site->edge_settings['allow_ips']).' IP na '.($site->edge_settings['allow_path'] ?? '/') : null,
                        ($site->edge_settings['hsts'] ?? false) ? 'HSTS' : null,
                    ])->filter()->join(' · ') ?: __('veřejný web')),
                TextEntry::make('php_settings')->label(__('PHP nastavení'))
                    ->state(fn () => ManagePhpSettings::summary($site))
                    ->visible(! $site->mode->isApp()),
            ]),
            Section::make('access')->label(__('Přístup a služby'))->icon('outline:key')->columns(2)->schema([
                TextEntry::make('ssh_access')->label('SSH / SFTP')->state(fn () => $site->ssh_access->getLabel())->badge()->color($site->ssh_access->getColor()),
                TextEntry::make('sftp')->label(__('Připojení'))
                    ->state(fn () => $site->ssh_access === SshAccess::Off ? __('zapnete v Nastavení → Přístup') : "sftp -P {$port} {$site->sshTarget()}")
                    ->copyable($site->ssh_access !== SshAccess::Off),
                TextEntry::make('databases')->label(__('Databáze'))
                    ->state(fn () => $site->databases->map(fn (Database $d) => $d->name.' ('.$d->engine->getLabel().')')->join(', ') ?: __('žádná'))
                    ->actions($site->databases->map(fn (Database $d) => Action::make('openDb'.$d->id)
                        ->label($d->name)->icon('outline:circle-stack')->color('gray')->size('sm')
                        ->tooltip(__('Otevřít databázi ').$d->name)
                        ->url(DatabaseResource::url('view', $d)))->all()),
                TextEntry::make('valkey')->label(__('Valkey (Redis)'))
                    ->state(fn () => $this->valkeyState($site))
                    ->actions($site->valkey !== null ? [SiteActions::valkeyFlush()->size('sm'), SiteActions::valkeyRestart()->size('sm')] : []),
                TextEntry::make('staging')->label(__('Staging'))
                    ->visible(fn () => $site->staging_of !== null || Site::query()->where('staging_of', $site->id)->exists())
                    ->state(fn () => $site->staging_of !== null
                        ? 'kopie webu '.(Site::query()->find($site->staging_of)?->domain ?? '?').__(' (pošta v Mailpitu, bez indexace)')
                        : 'kopie na '.Site::query()->where('staging_of', $site->id)->value('domain'))
                    ->actions(($other = $site->staging_of !== null ? Site::query()->find($site->staging_of) : Site::query()->where('staging_of', $site->id)->first())
                        ? [Action::make('openStaging')->label($other->domain)->icon('outline:beaker')->color('gray')->size('sm')->url(SiteResource::url('view', $other))] : []),
                TextEntry::make('xhprof')->label(__('Profilování (XHProf)'))
                    ->visible(fn () => $site->xhprof !== null)
                    ->state(fn () => __('měří ').($site->xhprof['ratio'] ?? 100).__(' % požadavků'))
                    ->actions([Action::make('openXhgui')->label(__('Otevřít XHGui'))->icon('outline:chart-bar')->color('gray')->size('sm')
                        ->url(ManageXhprof::viewerUrl($site), openInNewTab: true)]),
                TextEntry::make('mail')->label(__('Pošta'))
                    ->state(fn () => ($site->mail_mode === 'capture' ? __('web: zachytává se v Mailpitu') : __('web odesílá'))
                        .($mailDomain ? __(' · schránky na :domain', ['domain' => $mailDomain->domain]) : ''))
                    ->badge()->color($site->mail_mode === 'capture' ? 'warning' : 'success'),
                TextEntry::make('dns')->label('DNS')
                    ->state(fn () => $zone
                        ? __('zóna :zone · :where', ['zone' => $zone->domain, 'where' => $zone->provider->getLabel()])
                        : __('spravuje se mimo panel (co nastavit, ukáže záložka DNS)')),
                TextEntry::make('deploy')->label(__('Poslední deploy'))
                    ->state(fn () => $deploy ? $deploy->statusLabel().' · '.$deploy->started_at?->diffForHumans() : __('zatím žádný')),
            ]),
        ]);
    }

    /** One line about the site's Valkey: memory, keys, state. */
    private function valkeyState(Site $site): string
    {
        if ($site->valkey === null) {
            return __('vypnutý (zapnete v Nastavení → Výkon a ladění)');
        }
        $s = rescue(fn () => app(ManageValkey::class)->status($site), [], report: false);
        if (($s['active'] ?? '') !== 'active') {
            return __('neběží').(isset($s['active']) ? ' ('.$s['active'].')' : '');
        }

        return '127.0.0.1:'.($s['port'] ?? $site->valkey['port'] ?? '?').' · '.Number::fileSize($s['used_memory'] ?? 0, 1).' z '.$site->valkey['maxmemory_mb'].' MB · '
            .Number::format($s['keys'] ?? 0).__(' klíčů').($site->valkey['persist'] ? __(' · ukládá na disk') : '');
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
