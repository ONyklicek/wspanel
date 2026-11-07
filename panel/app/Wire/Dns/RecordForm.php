<?php

namespace App\Wire\Dns;

use App\Wire\Forms\Options;
use NyonCode\WireForms\Components\Select;
use NyonCode\WireForms\Components\Textarea;
use NyonCode\WireForms\Components\TextInput;

/** Form for one RRset (all values of one name + type). */
final class RecordForm
{
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS'];

    public const HINTS = [
        'A' => 'IPv4 adresa, např. 203.0.113.10',
        'AAAA' => 'IPv6 adresa',
        'CNAME' => 'Cílová doména, např. example.com',
        'MX' => 'Priorita a server, např. 10 mail.example.com',
        'TXT' => 'Text bez uvozovek, např. v=spf1 a mx ~all',
        'SRV' => 'priorita váha port cíl, např. 10 5 5060 sip.example.com',
        'CAA' => 'např. 0 issue "letsencrypt.org"',
        'NS' => 'Název nameserveru',
    ];

    /** @return list<mixed> */
    public static function fields(string $domain, bool $editing = false): array
    {
        return [
            TextInput::make('name')->label('Název')->placeholder('@')
                ->helperText("Prázdné nebo @ = {$domain}. Jinak např. www, mail, _dmarc.")
                ->readOnly($editing),
            Select::make('type')->rules([Options::rule()])->label('Typ')->options(array_combine(self::TYPES, self::TYPES))->required()->live()->disabled($editing),
            TextInput::make('ttl')->label('TTL (s)')->integer()->minValue(60)->maxValue(604800)->rules(['integer', 'between:60,604800'])->default(3600)->required(),
            Textarea::make('records')->label('Hodnoty')->rows(3)->required()
                ->helperText(fn ($get) => 'Jedna na řádek. '.(self::HINTS[$get('type')] ?? '')),
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
