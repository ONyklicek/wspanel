<?php

namespace App\Filament\Resources\Sites\Pages;

use App\Enums\Framework;
use App\Enums\SiteMode;
use App\Enums\SshAccess;
use App\Filament\Pages\Mail;
use App\Filament\Resources\Databases\DatabaseResource;
use App\Filament\Resources\DnsZones\DnsZoneResource;
use App\Filament\Resources\Sites\SiteActions;
use App\Filament\Resources\Sites\SiteResource;
use App\Filament\Resources\Sites\Widgets\SiteLogs;
use App\Filament\Resources\Sites\Widgets\SiteProcesses;
use App\Models\Database;
use App\Models\Deployment;
use App\Models\DnsZone;
use App\Models\Site;
use Filament\Actions\ActionGroup;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * @property-read Site $record
 */
class ViewSite extends ViewRecord
{
    protected static string $resource = SiteResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->record->domain;
    }

    public function getSubheading(): ?string
    {
        return $this->record->framework->getLabel().' · '.$this->record->runtimeLabel().' · '.$this->record->mode->getLabel();
    }

    protected function getHeaderActions(): array
    {
        return [
            SiteActions::open(),
            SiteActions::deploy()->color('primary'),
            SiteActions::enable(),
            SiteActions::restart(),
            ActionGroup::make(array_values(array_filter(
                SiteActions::secondary(),
                fn ($a) => ! in_array($a->getName(), ['restart', 'enable'], true),
            )))->label('Další')->icon(Heroicon::OutlinedEllipsisVertical)->button()->color('gray'),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [SiteProcesses::class, SiteLogs::class];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Web')
                ->columnSpan(2)
                ->columns(2)
                ->schema([
                    TextEntry::make('url')
                        ->label('Adresa')
                        ->state(fn (Site $record) => $record->url())
                        ->url(fn (Site $record) => $record->url(), shouldOpenInNewTab: true)
                        ->color('primary')
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->iconPosition('after'),
                    TextEntry::make('state')
                        ->label('Stav')
                        ->badge()
                        ->state(fn (Site $record) => $record->stateLabel())
                        ->color(fn (Site $record) => $record->stateColor())
                        ->helperText(fn (Site $record) => match (true) {
                            ! $record->enabled => 'Návštěvníci vidí stránku „Web je dočasně vypnutý“.',
                            $record->health_checked_at === null => 'Zatím neověřeno.',
                            default => 'HTTP '.($record->health_status ?? '—').' · ověřeno '.$record->health_checked_at->diffForHumans()
                                .($record->health_error ? ' · '.$record->health_error : ''),
                        }),
                    TextEntry::make('runtime')->label('Běží na')->state(fn (Site $record) => $record->runtimeLabel()
                        .($record->mode === SiteMode::Node && $record->port ? " · 127.0.0.1:{$record->port}" : '')),
                    TextEntry::make('framework')->label('Typ aplikace')->badge()->color('gray'),
                    TextEntry::make('system_user')->label('Systémový uživatel')->fontFamily('mono')->copyable(),
                    TextEntry::make('home')->label('Adresář webu')->fontFamily('mono')->copyable(),
                    TextEntry::make('doc_root')
                        ->label('Kořen webu (document root)')
                        ->fontFamily('mono')
                        ->copyable()
                        ->helperText(fn (Site $record) => $record->framework === Framework::Php
                            ? 'Přes SFTP nahrávejte do složky public_html/ (vede sem).'
                            : 'current/ ukazuje na aktivní release z posledního deploye.'),
                    TextEntry::make('created_at')->label('Založeno')->dateTime('j. n. Y H:i'),
                ]),
            Section::make('Přístup')
                ->columnSpan(1)
                ->schema([
                    TextEntry::make('ssh_access')
                        ->label('SSH / SFTP')
                        ->badge()
                        ->helperText(fn (Site $record) => $record->ssh_access === SshAccess::Off
                            ? 'Zapnete v menu Další → SSH / SFTP.'
                            : null),
                    TextEntry::make('sftp')
                        ->label('SFTP')
                        ->fontFamily('mono')
                        ->copyable()
                        ->visible(fn (Site $record) => $record->ssh_access !== SshAccess::Off)
                        ->state(fn (Site $record) => 'sftp -P '.config('panel.ssh_port').' '.$record->sshTarget()),
                    TextEntry::make('ssh')
                        ->label('SSH')
                        ->fontFamily('mono')
                        ->copyable()
                        ->visible(fn (Site $record) => $record->ssh_access === SshAccess::Shell)
                        ->state(fn (Site $record) => 'ssh -p '.config('panel.ssh_port').' '.$record->sshTarget()),
                    TextEntry::make('databases')
                        ->label('Databáze')
                        ->state(fn (Site $record) => $record->databases->map(fn (Database $d) => $d->name.' ('.$d->engine->getLabel().')')->all() ?: ['žádná'])
                        ->listWithLineBreaks()
                        ->url(fn (Site $record) => $record->databases->count() === 1
                            ? DatabaseResource::getUrl('view', ['record' => $record->databases->first()])
                            : DatabaseResource::getUrl('index')),
                    TextEntry::make('dns')
                        ->label('DNS')
                        ->state(fn (Site $record) => ($zone = DnsZone::query()->where('domain', $record->domain)->first())
                            ? 'zóna: '.$zone->provider->getLabel()
                            : 'zóna není v panelu (spravuje ji registrátor)')
                        ->url(fn (Site $record) => ($zone = DnsZone::query()->where('domain', $record->domain)->first())
                            ? DnsZoneResource::getUrl('view', ['record' => $zone])
                            : DnsZoneResource::getUrl('create')),
                    TextEntry::make('mail_mode')
                        ->label('Pošta')
                        ->badge()
                        ->state(fn (Site $record) => $record->mail_mode === 'capture' ? 'zachytává se v Mailpitu' : 'odesílá se')
                        ->color(fn (Site $record) => $record->mail_mode === 'capture' ? 'warning' : 'success')
                        ->url(Mail::getUrl()),
                    TextEntry::make('deploy')
                        ->label('Poslední deploy')
                        ->state(function (Site $record) {
                            $d = Deployment::query()->where('site_id', $record->id)->latest('started_at')->first();

                            return $d ? $d->statusLabel().' · '.$d->started_at?->diffForHumans() : 'zatím žádný';
                        })
                        ->url(fn (Site $record) => SiteResource::getUrl('deploy', ['record' => $record])),
                ]),
        ]);
    }
}
