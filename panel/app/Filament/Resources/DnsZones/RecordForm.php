<?php

namespace App\Filament\Resources\DnsZones;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

class RecordForm
{
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS'];

    /** @return array<int, mixed> */
    public static function fields(string $domain, bool $editing = false): array
    {
        return [
            TextInput::make('name')
                ->label('Název')
                ->helperText("Prázdné nebo @ = {$domain}. Jinak např. www nebo mail.")
                ->disabled($editing)
                ->dehydrated(),
            Select::make('type')->label('Typ')->options(array_combine(self::TYPES, self::TYPES))->required()->disabled($editing)->dehydrated()->live(),
            TextInput::make('ttl')->label('TTL (s)')->integer()->minValue(60)->maxValue(604800)->default(3600)->required(),
            Textarea::make('records')
                ->label('Hodnoty')
                ->rows(3)
                ->required()
                ->helperText(fn (Get $get) => 'Jedna na řádek. '.match ($get('type')) {
                    'A' => 'IPv4 adresa, např. 203.0.113.10',
                    'AAAA' => 'IPv6 adresa',
                    'CNAME' => 'Cílová doména, např. example.com',
                    'MX' => 'Priorita a server, např. 10 mail.example.com',
                    'TXT' => 'Text bez uvozovek, např. v=spf1 a mx ~all',
                    'SRV' => 'priorita váha port cíl, např. 10 5 5060 sip.example.com',
                    'CAA' => 'např. 0 issue "letsencrypt.org"',
                    'NS' => 'Název nameserveru',
                    default => '',
                }),
        ];
    }

    /** Relative name from the form -> FQDN inside the zone. */
    public static function fqdn(?string $name, string $domain): string
    {
        $name = strtolower(trim((string) $name, " .\t"));
        if ($name === '' || $name === '@' || $name === $domain) {
            return $domain;
        }

        return str_ends_with($name, '.'.$domain) ? $name : "{$name}.{$domain}";
    }

    /** @return list<string> */
    public static function values(?string $text): array
    {
        return collect(preg_split('/\R/', (string) $text) ?: [])->map(fn ($v) => trim($v))->filter()->values()->all();
    }
}
