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

    protected function headerActions(): array
    {
        $providers = array_map(fn (array $p) => $p['label'], ManageOffsite::PROVIDERS);

        return [
            Action::make('configure')
                ->label($this->status['configured'] ? 'Upravit úložiště' : 'Nastavit úložiště')
                ->icon('outline:cog-6-tooth')
                ->slideOver()
                ->modalHeading('Úložiště pro zálohy')
                ->modalDescription('Panel nejdřív zkusí do úložiště zapsat a smazat zkušební soubor; uloží se jen funkční nastavení.')
                ->fillFormUsing(function () {
                    $c = $this->status['config'];

                    return [
                        'enabled' => $this->status['configured'] ? (bool) ($c['enabled'] ?? false) : true,
                        'provider' => ManageOffsite::providerOf((string) ($c['endpoint'] ?? '')),
                        'endpoint' => (string) ($c['endpoint'] ?? ''),
                        'region' => (string) ($c['region'] ?? ''),
                        'bucket' => (string) ($c['bucket'] ?? ''),
                        'prefix' => (string) ($c['prefix'] ?? ''),
                        'path_style' => $this->status['configured'] ? (bool) ($c['path_style'] ?? true) : true,
                        'access_key' => (string) ($c['access_key'] ?? ''),
                        'secret_key' => '',
                        'keep_days' => (int) ($c['keep_days'] ?? 30),
                    ];
                })
                ->form([
                    Toggle::make('enabled')->label('Kopírovat nové zálohy mimo server'),
                    Select::make('provider')->label('Poskytovatel')->options($providers)->rules([Options::rule()])->live()
                        ->helperText('Vyplní adresu a region; upravte je podle svého účtu.')
                        ->afterStateUpdated(function ($state, $set) {
                            $p = ManageOffsite::PROVIDERS[$state] ?? null;
                            if ($p !== null) {
                                $set('endpoint', $p['endpoint']);
                                $set('region', $p['region']);
                                $set('path_style', $p['path_style']);
                            }
                        }),
                    TextInput::make('endpoint')->label('Adresa (endpoint)')->required()->maxLength(255)->placeholder('https://s3.eu-central-1.amazonaws.com')
                        ->rules(['required', 'url:https,http', 'max:255']),
                    TextInput::make('region')->label('Region')->maxLength(40)->rules(['nullable', 'regex:/^[a-z0-9-]{0,40}$/'])->placeholder('eu-central-1, u R2 auto'),
                    TextInput::make('bucket')->label('Bucket')->required()->rules(['required', 'regex:/^[a-z0-9][a-z0-9.\-_]{1,62}$/']),
                    TextInput::make('prefix')->label('Složka v bucketu')->placeholder('např. muj-server')->rules(['nullable', 'regex:/^[A-Za-z0-9._\-\/]{0,200}$/', 'not_regex:/\.\./'])
                        ->helperText('Hodí se, když jeden bucket sdílí víc serverů.'),
                    Toggle::make('path_style')->label('Adresovat bucket v cestě (path-style)')
                        ->helperText('Zapnuto pro většinu S3 kompatibilních úložišť; u Amazonu S3 vypnuto.'),
                    TextInput::make('access_key')->label('Access key')->required()->rules(['required', 'max:256']),
                    TextInput::make('secret_key')->label('Secret key')->password()->revealable()->rules(['nullable', 'max:256'])
                        ->helperText($this->status['has_secret'] ? 'Prázdné = ponechat uložený klíč. Uloží se jen na serveru (root, 0600).' : 'Uloží se jen na serveru (root, 0600), panel ho neuchovává.'),
                    TextInput::make('keep_days')->label('Uchovávat kopie (dní)')->numeric()->required()->rules(['required', 'integer', 'min:1', 'max:3650'])
                        ->helperText('Starší kopie se mažou po každém nahrání; nejnovější kopie zůstává vždy.'),
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
