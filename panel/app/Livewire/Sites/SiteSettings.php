<?php

namespace App\Livewire\Sites;

use App\Actions\Sites\ManagePhpSettings;
use App\Enums\SiteMode;
use App\Enums\SshAccess;
use App\Models\Site;
use App\Wire\Resources\SiteResource;
use App\Wire\Sites\SiteActions;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireCore\Foundation\Schema\Section;
use NyonCode\WireCore\Infolists\Components\TextEntry;
use NyonCode\WireCore\Infolists\Infolist;
use NyonCode\WirePanels\Resources\Pages\ViewPage;

/**
 * Everything a site is set to, one section per area, each showing its current
 * values beside the button that changes them (the same dialogs as before,
 * opened from here). A dialog has its own address too: ?action=tls opens it.
 */
class SiteSettings extends ViewPage
{
    protected static ?string $resource = SiteResource::class;

    public function getTitle(): ?string
    {
        $site = $this->nativeRecord();

        return __('Nastavení · ').($site instanceof Site ? $site->domain : 'web');
    }

    /** The dialogs the sections open (mounted by name against the site). */
    protected function actions(): array
    {
        return SiteActions::settings();
    }

    /**
     * A section button opening one of the dialogs; hidden where the dialog
     * itself would be (a Node site has no PHP settings, a client no owner).
     */
    private function open(string $name, string $label, Site $site, ?string $icon = null): ?Action
    {
        $action = collect(SiteActions::settings())->first(fn (Action $a) => $a->getName() === $name);
        if ($action === null || $action->isHidden($site)) {
            return null;
        }

        return Action::make('open_'.$name)->label($label)->icon($icon ?? 'outline:pencil-square')->color('gray')->size('sm')
            ->action(fn ($livewire) => $livewire->mountAction($name));
    }

    /** @param list<Action|null> $actions */
    private static function present(array $actions): array
    {
        return array_values(array_filter($actions));
    }

    public function infolist(): Infolist
    {
        /** @var Site $site */
        $site = $this->nativeRecord();
        $edge = $site->edge_settings ?? [];
        $php = $site->mode !== SiteMode::Node;

        return Infolist::make()->record($site)->livewireComponent($this)->schema([
            Section::make('domains')->label(__('Domény a HTTPS'))->icon('outline:globe-alt')->columns(2)
                ->headerActions(self::present([$this->open('edge', __('Domény a přístup'), $site), $this->open('tls', 'HTTPS', $site, 'outline:lock-closed')]))
                ->schema([
                    TextEntry::make('domain')->label(__('Hlavní doména'))->copyable(),
                    TextEntry::make('tls')->label('HTTPS')->state(fn () => match ($site->tls_mode) {
                        'internal' => __('interní certifikační autorita'),
                        'custom' => __('vlastní certifikát'),
                        default => "Let's Encrypt",
                    }),
                    TextEntry::make('aliases')->label(__('Další domény'))->state(fn () => implode(', ', $edge['aliases'] ?? []) ?: __('žádné')),
                    TextEntry::make('redirect_hosts')->label(__('Přesměrované domény'))->state(fn () => implode(', ', $edge['redirect_hosts'] ?? []) ?: __('žádné')),
                    TextEntry::make('redirects')->label(__('Přesměrování cest'))->state(fn () => count($edge['redirects'] ?? []) ?: __('žádné')),
                    TextEntry::make('headers')->label(__('Hlavičky'))->state(fn () => collect([
                        ($edge['hsts'] ?? false) ? 'HSTS' : null,
                        ($edge['security_headers'] ?? false) ? __('bezpečnostní hlavičky') : null,
                        count($edge['headers'] ?? []) ? __(':n vlastních', ['n' => count($edge['headers'])]) : null,
                    ])->filter()->join(' · ') ?: __('výchozí')),
                ]),
            Section::make('access')->label(__('Přístup'))->icon('outline:key')->columns(2)
                ->headerActions(self::present([$this->open('ssh', 'SSH / SFTP', $site, 'outline:command-line'), $this->open('credentials', __('Přístupové údaje'), $site, 'outline:identification')]))
                ->schema([
                    TextEntry::make('ssh_access')->label('SSH / SFTP')->state(fn () => $site->ssh_access->getLabel())->badge()->color($site->ssh_access->getColor()),
                    TextEntry::make('ssh_login')->label(__('Přihlášení'))->state(fn () => $site->ssh_access === SshAccess::Off ? '—' : ($site->ssh_password ? __('klíč nebo heslo') : __('jen klíč'))),
                    TextEntry::make('auth')->label(__('Heslo na web'))->state(fn () => ($edge['auth_path'] ?? '') === '' ? __('bez hesla') : __('na :path (uživatel :user)', ['path' => $edge['auth_path'], 'user' => $edge['auth_user'] ?? '?'])),
                    TextEntry::make('allow_ips')->label(__('Povolené IP adresy'))->state(fn () => ($edge['allow_ips'] ?? []) === [] ? __('kdokoli') : implode(', ', $edge['allow_ips']).' · '.($edge['allow_path'] ?? '/')),
                ]),
            Section::make('runtime')->label(__('Běh a PHP'))->icon('outline:cpu-chip')->columns(2)
                ->headerActions(self::present([
                    $this->open('setVersion', __('Verze'), $site, 'outline:arrow-path'),
                    $this->open('switchMode', __('Režim'), $site, 'outline:arrows-right-left'),
                    $this->open('phpSettings', __('PHP nastavení'), $site, 'outline:adjustments-horizontal'),
                ]))
                ->schema([
                    TextEntry::make('runtime')->label(__('Běží na'))->state(fn () => $site->runtimeLabel()),
                    TextEntry::make('framework')->label(__('Typ aplikace'))->state(fn () => $site->framework->getLabel())->badge()->color('gray'),
                    TextEntry::make('php_settings')->label(__('PHP nastavení'))->state(fn () => ManagePhpSettings::summary($site))->visible($php)->columnSpanFull(),
                ]),
            Section::make('webserver')->label(__('Pravidla webserveru'))->icon('outline:code-bracket')
                ->headerActions(self::present([$this->open('webRules', __('Upravit pravidla'), $site)]))
                ->schema([
                    TextEntry::make('web_rules')->hiddenLabel()->state(fn () => trim((string) $site->web_rules) === ''
                        ? __('Žádná vlastní pravidla (přepisy, hlavičky, omezení) nad rámec výchozích.')
                        : __(':n řádků vlastních pravidel (Caddy)', ['n' => count(array_filter(explode("\n", trim((string) $site->web_rules))))])),
                ]),
            Section::make('tools')->label(__('Výkon a ladění'))->icon('outline:bolt')->columns(2)
                ->headerActions(self::present([$this->open('valkey', 'Valkey', $site, 'outline:server-stack'), $this->open('xhprof', 'XHProf', $site, 'outline:chart-bar')]))
                ->schema([
                    TextEntry::make('valkey')->label(__('Valkey (Redis)'))->state(fn () => $site->valkey === null ? __('vypnutý')
                        : __(':mb MB, :policy', ['mb' => $site->valkey['maxmemory_mb'] ?? '?', 'policy' => $site->valkey['policy'] ?? '']).(($site->valkey['persist'] ?? false) ? __(' · ukládá na disk') : '')),
                    TextEntry::make('xhprof')->label(__('Profilování (XHProf)'))->state(fn () => $site->xhprof === null ? __('vypnuté') : __('měří ').($site->xhprof['ratio'] ?? 100).__(' % požadavků')),
                ]),
            Section::make('copies')->label(__('Staging a přesun'))->icon('outline:beaker')
                ->headerActions(self::present([$this->open('staging', $site->staging_of ? __('Nasadit na produkci') : __('Vytvořit staging'), $site), $this->open('import', __('Importovat web'), $site, 'outline:arrow-down-tray')]))
                ->schema([
                    TextEntry::make('staging')->hiddenLabel()->state(fn () => match (true) {
                        $site->staging_of !== null => __('Tento web je staging webu :domain.', ['domain' => Site::query()->find($site->staging_of)?->domain ?? '?']),
                        ($copy = Site::query()->where('staging_of', $site->id)->value('domain')) !== null => __('Staging běží na :domain.', ['domain' => $copy]),
                        default => __('Staging je kopie webu na vlastní doméně (pošta v Mailpitu, bez indexace). Import převezme web z cPanelu nebo archivu.'),
                    }),
                ]),
            Section::make('owner')->label(__('Vlastník'))->icon('outline:user-group')
                ->visible(fn () => $this->open('assignClient', '', $site) !== null)
                ->headerActions(self::present([$this->open('assignClient', __('Změnit vlastníka'), $site)]))
                ->schema([
                    TextEntry::make('client')->hiddenLabel()->state(fn () => $site->client?->name ?? __('správce')),
                ]),
            Section::make('danger')->label(__('Nebezpečná zóna'))->icon('outline:exclamation-triangle')
                ->headerActions(self::present([
                    $this->open('disable', __('Vypnout web'), $site, 'outline:pause'),
                    $this->open('enable', __('Zapnout web'), $site, 'outline:play'),
                    $this->open('delete', __('Smazat web'), $site, 'outline:trash')?->color('danger'),
                ]))
                ->schema([
                    TextEntry::make('danger')->hiddenLabel()->state(fn () => $site->enabled
                        ? __('Vypnutý web ukazuje návštěvníkům stránku „Web je dočasně vypnutý“, data zůstanou. Smazání odebere procesy a uživatele, soubory jdou do koše na serveru, databáze zůstanou.')
                        : __('Web je vypnutý: návštěvníci vidí stránku „Web je dočasně vypnutý“.')),
                ]),
        ]);
    }
}
