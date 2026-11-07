<?php

namespace App\Livewire\Pages;

use App\Actions\Databases\ManageDatabaseServers;
use App\Enums\DatabaseEngine;
use App\Services\Agent\AgentException;
use App\Services\Agent\SystemInfo;
use App\Support\Toast;
use App\Wire\Forms\Options;
use App\Wire\Resources\DatabaseResource;
use Illuminate\Support\Carbon;
use NyonCode\WireCore\Actions\Action;
use NyonCode\WireForms\Components\Checkbox;
use NyonCode\WireForms\Components\Display\Placeholder;
use NyonCode\WireForms\Components\Radio;
use NyonCode\WireForms\Components\Textarea;
use NyonCode\WireForms\Components\TextInput;
use NyonCode\WirePanels\Pages\Page;

/**
 * MariaDB / PostgreSQL as servers: superuser password, access from the
 * internet, and the database tools over every database.
 */
class DatabaseServersPage extends Page
{
    protected static string $view = 'livewire.pages.database-servers';

    protected ?string $title = 'Databázové servery';

    protected static ?string $slug = 'db-servers';

    protected static ?string $navigationLabel = 'Servery';

    protected static ?string $navigationIcon = 'outline:server';

    protected static ?string $navigationParent = DatabaseResource::class;

    protected static int $navigationSort = 25;

    /** @var array<string, array<string, mixed>> */
    public array $servers = [];

    public ?string $error = null;

    public function mount(): void
    {
        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        try {
            $this->servers = app(ManageDatabaseServers::class)->status();
            $this->error = null;
        } catch (AgentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public static function engineLabel(string $engine): string
    {
        return DatabaseEngine::tryFrom($engine)?->getLabel() ?? $engine;
    }

    /** The address clients use: the panel's host name (it points at this server). */
    public function host(): string
    {
        return (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: config('panel.public_ipv4') ?: 'server');
    }

    /** @return list<string> */
    public function tools(string $engine): array
    {
        $tools = app(SystemInfo::class)->dbTools();

        return array_values(array_filter($tools, fn (string $t) => $t === 'adminer' || $engine === 'mariadb'));
    }

    public function setAt(string $engine): ?string
    {
        $at = $this->servers[$engine]['root_set_at'] ?? null;

        return $at ? Carbon::parse($at)->timezone(config('app.timezone'))->format('j. n. Y H:i') : null;
    }

    /** @return array<string, string> example client commands */
    public function examples(string $engine): array
    {
        $s = $this->servers[$engine] ?? [];
        $host = $this->host();
        $port = (int) ($s['port'] ?? 0);

        return $engine === 'mariadb'
            ? [
                'Superuživatel' => "mariadb -h {$host} -P {$port} -u root -p --ssl",
                'Databáze webu' => "mariadb -h {$host} -P {$port} -u <název databáze> -p --ssl <název databáze>",
            ]
            : [
                'Superuživatel' => "psql \"host={$host} port={$port} user=postgres dbname=postgres sslmode=require\"",
                'Databáze webu' => "psql \"host={$host} port={$port} user=<název databáze> dbname=<název databáze> sslmode=require\"",
            ];
    }

    protected function actions(): array
    {
        $actions = [];
        foreach (array_keys($this->servers) as $engine) {
            $actions[] = $this->rootPasswordAction($engine);
            $actions[] = $this->accessAction($engine);
        }

        return $actions;
    }

    public function rootPasswordAction(string $engine): Action
    {
        $label = self::engineLabel($engine);
        $user = $engine === 'mariadb' ? 'root' : 'postgres';
        $hasPassword = (bool) ($this->servers[$engine]['root_password'] ?? false);

        return Action::make("rootPassword_{$engine}")
            ->label($hasPassword ? 'Změnit heslo' : 'Nastavit heslo')
            ->icon('outline:key')
            ->color('gray')
            ->modalHeading("Heslo uživatele {$user} ({$label})")
            ->modalDescription("Superuživatel {$user} vidí a smí měnit všechny databáze. Heslo potřebujete pro připojení z vlastního klienta (TablePlus, DBeaver, DataGrip…) nebo z jiného serveru. Panel heslo neukládá; správa serveru na něm nezávisí.")
            ->form([
                Radio::make('mode')->rules([Options::rule()])
                    ->label('Heslo')
                    ->options(array_filter([
                        'generate' => 'Vygenerovat silné heslo',
                        'own' => 'Zadat vlastní',
                        'remove' => $hasPassword ? 'Zrušit heslo (jen ze serveru)' : null,
                    ]))
                    ->descriptions(['generate' => 'Zobrazí se jednou po uložení.', 'remove' => "Přihlášení {$user} po síti přestane fungovat."])
                    ->default('generate')
                    ->required()
                    ->live(),
                TextInput::make('password')
                    ->label('Nové heslo')
                    ->password()
                    ->revealable()
                    ->minLength(16)
                    ->maxLength(128)
                    ->rules(['min:16', 'max:128', 'regex:/^[A-Za-z0-9!#$%&()*+,\-.\/:;<=>?@\[\]^_{|}~]+$/'])
                    ->validationMessages(['regex' => 'Písmena, číslice a interpunkce bez uvozovek, zpětného lomítka, backticku a mezer.'])
                    ->helperText('Alespoň 16 znaků.')
                    ->visibleWhen('mode', 'own')
                    ->requiredIf('mode', 'own'),
            ])
            ->modalSubmitActionLabel('Uložit')
            ->action(function (array $data) use ($engine, $user, $label) {
                $servers = app(ManageDatabaseServers::class);
                try {
                    if ($data['mode'] === 'remove') {
                        $servers->removeRootPassword($engine);
                        Toast::success("Heslo uživatele {$user} zrušeno", 'Přihlásit se jde jen ze serveru.');
                    } else {
                        $generated = $servers->setRootPassword($engine, $data['mode'] === 'own' ? $data['password'] : null);
                        $generated !== null
                            ? Toast::secret("Heslo uživatele {$user} ({$label})", "Uživatel {$user}, heslo (zobrazí se jen teď): {$generated}")
                            : Toast::success("Heslo uživatele {$user} nastaveno");
                    }
                } catch (AgentException $e) {
                    Toast::error('Heslo se nepodařilo nastavit', $e->getMessage());
                }
                $this->refreshStatus();
            });
    }

    public function accessAction(string $engine): Action
    {
        $label = self::engineLabel($engine);
        $port = (int) ($this->servers[$engine]['port'] ?? 0);
        $clientIp = request()->ip();

        return Action::make("access_{$engine}")
            ->label('Přístup z internetu')
            ->icon('outline:globe-europe-africa')
            ->modalHeading("{$label}: přístup z internetu")
            ->modalDescription('Připojení zvenku jde vždy jen šifrovaně (TLS) a s heslem. Opakovaně chybná přihlášení zablokuje fail2ban. Weby na tomto serveru se připojují lokálně a změna je neovlivní.')
            ->modalWidth('2xl')
            ->fillFormUsing(fn () => [
                'access' => ManageDatabaseServers::access($this->servers[$engine] ?? []),
                'allow' => implode("\n", $this->servers[$engine]['allow'] ?? []),
            ])
            ->form([
                Radio::make('access')->rules([Options::rule()])
                    ->label('Kdo se může připojit')
                    ->options([
                        ManageDatabaseServers::ACCESS_LOCAL => 'Jen tento server',
                        ManageDatabaseServers::ACCESS_ALLOWLIST => 'Vybrané adresy',
                        ManageDatabaseServers::ACCESS_ANYWHERE => 'Kdokoli z internetu',
                    ])
                    ->descriptions([
                        ManageDatabaseServers::ACCESS_LOCAL => "Port {$port} je zavřený. Ze svého počítače se připojíte přes SSH tunel.",
                        ManageDatabaseServers::ACCESS_ALLOWLIST => "Port {$port} se otevře jen pro zadané IP adresy a sítě (kancelář, VPN, CI).",
                        ManageDatabaseServers::ACCESS_ANYWHERE => "Port {$port} bude otevřený celému internetu a roboti začnou zkoušet hesla. Jen se silnými hesly.",
                    ])
                    ->colors([ManageDatabaseServers::ACCESS_ANYWHERE => 'danger'])
                    ->cards()
                    ->required()
                    ->live(),
                Textarea::make('allow')
                    ->label('Povolené adresy')
                    ->rows(4)
                    ->placeholder("203.0.113.7\n10.8.0.0/24")
                    ->helperText('Jedna IP adresa nebo síť (CIDR) na řádek.'.($clientIp ? " Vaše adresa: {$clientIp}." : ''))
                    ->visibleWhen('access', ManageDatabaseServers::ACCESS_ALLOWLIST)
                    ->requiredIf('access', ManageDatabaseServers::ACCESS_ALLOWLIST)
                    ->rules([function (string $attribute, mixed $value, \Closure $fail) {
                        $list = ManageDatabaseServers::parseAllow((string) $value);
                        if (count($list) > 50) {
                            $fail('Nejvýš 50 adres.');
                        }
                        foreach ($list as $entry) {
                            if (! ManageDatabaseServers::validAddress($entry)) {
                                $fail("{$entry} není IP adresa ani síť.");

                                return;
                            }
                        }
                    }]),
                Checkbox::make('understood')
                    ->label("Rozumím, že {$label} bude dostupná z celého internetu.")
                    ->visibleWhen('access', ManageDatabaseServers::ACCESS_ANYWHERE)
                    // Hidden (and so not validated) unless "anywhere" is chosen.
                    ->rules(['accepted'])
                    ->validationMessages(['accepted' => 'Potvrďte, že rozumíte riziku.']),
                Placeholder::make('users')
                    ->label('Kdo se přihlásí')
                    ->content($engine === 'mariadb'
                        ? 'Vlastníci databází (stejné jméno a heslo jako v .env webu) a root, pokud má nastavené heslo.'
                        : 'Vlastníci databází (stejné jméno a heslo jako v .env webu) a postgres, pokud má nastavené heslo.')
                    ->hiddenWhen('access', ManageDatabaseServers::ACCESS_LOCAL),
            ])
            ->modalSubmitActionLabel('Uložit')
            ->action(function (array $data) use ($engine, $label, $port) {
                try {
                    $out = app(ManageDatabaseServers::class)->setAccess($engine, $data['access'], ManageDatabaseServers::parseAllow($data['allow'] ?? ''));
                    match ($data['access']) {
                        ManageDatabaseServers::ACCESS_LOCAL => Toast::success("{$label} je dostupná jen z tohoto serveru", "Port {$port} je zavřený."),
                        ManageDatabaseServers::ACCESS_ANYWHERE => Toast::warning("{$label} je dostupná z internetu", "Port {$port} je otevřený všem. Připojení jen přes TLS."),
                        default => Toast::success("{$label} je dostupná z vybraných adres", implode(', ', $out['allow'] ?? [])),
                    };
                    if (isset($out['warning'])) {
                        Toast::warning('Ochrana proti hádání hesel', (string) $out['warning']);
                    }
                } catch (AgentException $e) {
                    Toast::error('Nastavení se nepodařilo uložit', $e->getMessage());
                }
                $this->refreshStatus();
            });
    }
}
