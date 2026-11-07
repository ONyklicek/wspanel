<?php

namespace App\Livewire\Pages;

use App\Actions\Server\ManageOffsite;
use App\Services\Agent\AgentException;
use App\Support\Toast;
use App\Wire\Forms\Options;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WireForms\Components\Toggle;
use NyonCode\WirePanels\Pages\Page;

/** Copies of all backups in S3-compatible storage outside the server. */
class OffsitePage extends Page
{
    protected static string $view = 'livewire.pages.offsite';

    protected ?string $title = 'Zálohy mimo server';

    protected static ?string $slug = 'offsite';

    protected static ?string $navigationLabel = 'Zálohy mimo server';

    protected static ?string $navigationIcon = 'outline:cloud-arrow-up';

    protected static ?string $navigationGroup = 'server';

    protected static int $navigationSort = 45;

    /** @var array{configured: bool, has_secret: bool, config: array<string, mixed>} */
    public array $status = ['configured' => false, 'has_secret' => false, 'config' => []];

    public ?string $error = null;

    public function mount(): void
    {
        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        try {
            $this->status = app(ManageOffsite::class)->status();
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function enabled(): bool
    {
        return (bool) ($this->status['config']['enabled'] ?? false);
    }

    /** Where the copies go, for the status box. */
    public function where(): string
    {
        $c = $this->status['config'];

        return match ($c['type'] ?? 's3') {
            'ftp' => 'ftp://'.($c['host'] ?? '').(! empty($c['port']) ? ':'.$c['port'] : '').'/'.($c['prefix'] ?? ''),
            'smb' => '\\\\'.($c['host'] ?? '').'\\'.($c['share'] ?? '').(($c['prefix'] ?? '') !== '' ? '\\'.str_replace('/', '\\', $c['prefix']) : ''),
            default => ($c['bucket'] ?? '').(($c['prefix'] ?? '') !== '' ? '/'.$c['prefix'] : '').' @ '.($c['endpoint'] ?? ''),
        };
    }

    public function typeLabel(): string
    {
        return ManageOffsite::TYPES[$this->status['config']['type'] ?? 's3'] ?? 'S3';
    }

    protected function headerActions(): array
    {
        $providers = array_map(fn (array $p) => $p['label'], ManageOffsite::PROVIDERS);
        $secretHelp = $this->status['has_secret']
            ? 'Prázdné = ponechat uložené (jen když se nemění server, port, účet, šifrování ani sdílená složka). Uloží se jen na serveru (root, 0600).'
            : 'Uloží se jen na serveru (root, 0600), panel ho neuchovává.';

        return [
            Action::make('signingKey')
                ->label('Klíč pro ověření záloh')
                ->icon('outline:key')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Zobrazit klíč pro ověření záloh?')
                ->modalDescription('Každá kopie mimo server je podepsaná tímto klíčem a panel stáhne zpět jen kopii s platným podpisem. Uložte si ho bezpečně mimo server (správce hesel): na novém serveru ho zadáte v nastavení úložiště a staré zálohy půjde ověřit. Kdo klíč zná, dokáže podepsat podvrženou zálohu.')
                ->action(function () {
                    try {
                        Toast::secret('Klíč pro ověření záloh', app(ManageOffsite::class)->signingKey());
                    } catch (AgentException $e) {
                        Toast::error('Klíč se nepodařilo načíst', $e->getMessage());
                    }
                }),
            Action::make('configure')
                ->label($this->status['configured'] ? 'Upravit úložiště' : 'Nastavit úložiště')
                ->icon('outline:cog-6-tooth')
                ->slideOver()
                ->modalHeading('Úložiště pro zálohy')
                ->modalDescription('Panel nejdřív zkusí do úložiště zapsat, přečíst a smazat zkušební soubor; uloží se jen funkční nastavení.')
                ->fillFormUsing(function () {
                    $c = $this->status['config'];
                    $configured = $this->status['configured'];

                    return [
                        'enabled' => $configured ? (bool) ($c['enabled'] ?? false) : true,
                        'type' => (string) ($c['type'] ?? 's3'),
                        'provider' => ManageOffsite::providerOf((string) ($c['endpoint'] ?? '')),
                        'endpoint' => (string) ($c['endpoint'] ?? ''),
                        'region' => (string) ($c['region'] ?? ''),
                        'bucket' => (string) ($c['bucket'] ?? ''),
                        'path_style' => $configured ? (bool) ($c['path_style'] ?? false) : true,
                        'access_key' => (string) ($c['access_key'] ?? ''),
                        'secret_key' => '',
                        'host' => (string) ($c['host'] ?? ''),
                        'port' => ($c['port'] ?? 0) ?: null,
                        'username' => (string) ($c['username'] ?? ''),
                        'password' => '',
                        'ftp_tls' => (string) (($c['ftp_tls'] ?? '') ?: 'explicit'),
                        'tls_pin' => (string) ($c['tls_pin'] ?? ''),
                        'share' => (string) ($c['share'] ?? ''),
                        'smb_domain' => (string) ($c['smb_domain'] ?? ''),
                        'smb_encrypt' => $configured && ($c['type'] ?? '') === 'smb' ? (bool) ($c['smb_encrypt'] ?? false) : true,
                        'prefix' => (string) ($c['prefix'] ?? ''),
                        'keep_days' => (int) ($c['keep_days'] ?? 30),
                        'signing_key' => '',
                    ];
                })
                ->form([
                    Toggle::make('enabled')->label('Kopírovat nové zálohy mimo server'),
                    Select::make('type')->label('Typ úložiště')->options(ManageOffsite::TYPES)->required()->live()
                        ->rules(['required', 'in:'.implode(',', array_keys(ManageOffsite::TYPES))]),

                    // S3
                    Select::make('provider')->label('Poskytovatel')->options($providers)->rules([Options::rule()])->live()
                        ->visibleWhen('type', 's3')
                        ->helperText('Vyplní adresu a region; upravte je podle svého účtu.')
                        ->afterStateUpdated(function ($state, $set) {
                            $p = ManageOffsite::PROVIDERS[$state] ?? null;
                            if ($p !== null) {
                                $set('endpoint', $p['endpoint']);
                                $set('region', $p['region']);
                                $set('path_style', $p['path_style']);
                            }
                        }),
                    TextInput::make('endpoint')->label('Adresa (endpoint)')->placeholder('https://s3.eu-central-1.amazonaws.com')
                        ->visibleWhen('type', 's3')->requiredIf('type', 's3')->rules(['nullable', 'url:https,http', 'max:255']),
                    TextInput::make('region')->label('Region')->placeholder('eu-central-1, u R2 auto')
                        ->visibleWhen('type', 's3')->rules(['nullable', 'regex:/^[a-z0-9-]{0,40}$/']),
                    TextInput::make('bucket')->label('Bucket')
                        ->visibleWhen('type', 's3')->requiredIf('type', 's3')->rules(['nullable', 'regex:/^[a-z0-9][a-z0-9.\-_]{1,62}$/']),
                    Toggle::make('path_style')->label('Adresovat bucket v cestě (path-style)')->visibleWhen('type', 's3')
                        ->helperText('Zapnuto pro většinu S3 kompatibilních úložišť; u Amazonu S3 vypnuto.'),
                    TextInput::make('access_key')->label('Access key')
                        ->visibleWhen('type', 's3')->requiredIf('type', 's3')->rules(['nullable', 'max:256']),
                    TextInput::make('secret_key')->label('Secret key')->password()->revealable()
                        ->visibleWhen('type', 's3')->rules(['nullable', 'max:256'])->helperText($secretHelp),

                    // FTP, SMB
                    TextInput::make('host')->label('Server')->placeholder('nas.example.com nebo 192.168.1.10')
                        ->visibleWhen('type', ['ftp', 'smb'])->requiredIf('type', ['ftp', 'smb'])->rules(['nullable', 'regex:/^[A-Za-z0-9][A-Za-z0-9.\-:\[\]]{0,252}$/']),
                    TextInput::make('port')->label('Port')->numeric()->placeholder('výchozí')
                        ->visibleWhen('type', ['ftp', 'smb'])->rules(['nullable', 'integer', 'min:1', 'max:65535']),
                    Select::make('ftp_tls')->label('Šifrování')->options(ManageOffsite::FTP_TLS)->live()
                        ->visibleWhen('type', 'ftp')->rules(['nullable', 'in:'.implode(',', array_keys(ManageOffsite::FTP_TLS))])
                        ->helperText(fn ($get) => $get('ftp_tls') === 'none'
                            ? 'Heslo i zálohy (včetně .env webů) půjdou sítí čitelně. Jen pro důvěryhodnou vnitřní síť.'
                            : 'Přihlášení i přenos dat jsou šifrované.'),
                    TextInput::make('tls_pin')->label('Otisk certifikátu (SHA-256)')->placeholder('AB:CD:… (jen pro vlastní certifikát NASu)')
                        ->visibleWhen('type', 'ftp')->rules(['nullable', 'regex:/^([0-9A-Fa-f]{2}:?){31}[0-9A-Fa-f]{2}$/'])
                        ->helperText('Prázdné = certifikát musí vydat důvěryhodná autorita. Když server používá vlastní certifikát, panel při testu ukáže jeho otisk; po ověření ho sem vložte.'),
                    TextInput::make('share')->label('Sdílená složka')->placeholder('zalohy')
                        ->visibleWhen('type', 'smb')->requiredIf('type', 'smb')->rules(['nullable', 'regex:/^[A-Za-z0-9._\-$ ]{1,80}$/']),
                    TextInput::make('username')->label('Uživatel')
                        ->visibleWhen('type', ['ftp', 'smb'])->requiredIf('type', ['ftp', 'smb'])->rules(['nullable', 'max:128', 'not_regex:/["\r\n]/']),
                    TextInput::make('password')->label('Heslo')->password()->revealable()
                        ->visibleWhen('type', ['ftp', 'smb'])->rules(['nullable', 'max:256'])->helperText($secretHelp),
                    TextInput::make('smb_domain')->label('Doména / pracovní skupina')->placeholder('nepovinné, např. WORKGROUP')
                        ->visibleWhen('type', 'smb')->rules(['nullable', 'max:128', 'not_regex:/["\r\n]/']),
                    Toggle::make('smb_encrypt')->label('Vyžadovat šifrování SMB3')->visibleWhen('type', 'smb')
                        ->helperText('Doporučeno; vypněte jen pro starší NAS bez podpory šifrování.'),

                    // Common
                    TextInput::make('prefix')->label('Složka')->placeholder('např. muj-server')->rules(['nullable', 'regex:/^[A-Za-z0-9._\-\/]{0,200}$/', 'not_regex:/\.\./'])
                        ->helperText('Hodí se, když jedno úložiště sdílí víc serverů.'),
                    TextInput::make('keep_days')->label('Uchovávat kopie (dní)')->numeric()->required()->rules(['required', 'integer', 'min:1', 'max:3650'])
                        ->helperText('Starší kopie se mažou po každém nahrání; nejnovější kopie zůstává vždy.'),
                    TextInput::make('signing_key')->label('Klíč pro ověření záloh z jiného serveru')->password()->revealable()
                        ->rules(['nullable', 'regex:/^[0-9a-fA-F]{64}$/'])
                        ->helperText('Jen při obnově na novém serveru: vložte klíč starého serveru, aby šlo jeho zálohy ověřit. Jinak nechte prázdné.'),
                ])
                ->modalSubmitActionLabel('Otestovat a uložit')
                ->action(function (array $data) {
                    try {
                        app(ManageOffsite::class)->save($data);
                        Toast::success($data['enabled'] ? 'Úložiště funguje, nové zálohy se budou kopírovat' : 'Uloženo, kopírování je vypnuté');
                    } catch (AgentException $e) {
                        Toast::error('Úložiště se nepodařilo nastavit', $e->getMessage());
                    }
                    $this->refreshStatus();
                }),
        ];
    }
}
