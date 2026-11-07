<?php

namespace App\Filament\Resources\Sites\Schemas;

use App\Enums\Framework;
use App\Enums\SiteMode;
use App\Services\Agent\SystemInfo;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SiteForm
{
    /** Same rule as the agent's validator (punycode, lowercase, 2+ labels). */
    public const DOMAIN_REGEX = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+([a-z]{2,63}|xn--[a-z0-9-]{1,59})$/';

    public static function configure(Schema $schema): Schema
    {
        $system = app(SystemInfo::class);

        return $schema->components([
            Section::make('Nový web')
                ->columns(2)
                ->schema([
                    TextInput::make('domain')
                        ->label('Doména')
                        ->placeholder('example.com')
                        ->required()
                        ->maxLength(253)
                        ->dehydrateStateUsing(fn (?string $state) => self::normalizeDomain($state))
                        ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                            if (! preg_match(self::DOMAIN_REGEX, self::normalizeDomain($value))) {
                                $fail('Neplatná doména.');
                            }
                        })
                        ->unique(ignoreRecord: true)
                        ->helperText('Doména musí DNS záznamem mířit na tento server, jinak nepůjde vydat certifikát.')
                        ->columnSpanFull(),
                    Select::make('framework')
                        ->label('Framework')
                        ->options(Framework::class)
                        ->default(Framework::Laravel->value)
                        ->required()
                        ->helperText('Určuje adresářovou strukturu, .env a později deploy a workery.'),
                    Select::make('php_version')
                        ->label('Verze PHP')
                        ->options(fn () => $system->phpVersionOptions())
                        ->default(fn () => $system->defaultPhpVersion())
                        ->required()
                        ->helperText(fn () => $system->phpVersionOptions() === []
                            ? 'Agent není dostupný, verze PHP nelze načíst.'
                            : null),
                    Select::make('mode')
                        ->label('Režim')
                        ->options([SiteMode::Classic->value => SiteMode::Classic->getLabel()])
                        ->default(SiteMode::Classic->value)
                        ->required()
                        ->selectablePlaceholder(false)
                        ->helperText('Worker (FrankenPHP) a Node SSR přibudou ve Fázi 3.'),
                ]),
        ]);
    }

    public static function normalizeDomain(?string $domain): string
    {
        $domain = strtolower(trim((string) $domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim(explode('/', $domain)[0], '.');
        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7f]/', $domain)) {
            $domain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain;
        }

        return $domain;
    }
}
