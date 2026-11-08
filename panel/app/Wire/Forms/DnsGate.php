<?php

namespace App\Wire\Forms;

use App\Services\Dns\DnsPrerequisites;
use Closure;
use NyonCode\WireCore\Foundation\Schema\Callout;
use NyonCode\WireForms\Components\Checkbox;

/**
 * Form fields that hold a feature back until the DNS it needs is there: what
 * is missing, and a confirmation to switch it on anyway (DNS is often set
 * right after, and takes its TTL to spread). Nothing shows while DNS is fine
 * or nothing new depends on it.
 */
final class DnsGate
{
    /**
     * @param  Closure(DnsPrerequisites, callable $get): list<array{label: string, ok: bool|null, detail: string}>  $checks
     * @return list<mixed>
     */
    public static function fields(Closure $checks): array
    {
        $failing = fn (callable $get): array => DnsPrerequisites::failing($checks(app(DnsPrerequisites::class), $get));

        return [
            Callout::make()->warning()->heading(__('V DNS zatím chybí'))
                ->content(fn ($get) => collect($failing($get))->map(fn ($c) => $c['label'].': '.$c['detail'])->implode(' · '))
                ->visible(fn ($get) => $failing($get) !== []),
            Checkbox::make('dns_anyway')->label(__('Zapnout i tak: DNS nastavím, funkce začne fungovat, až se záznamy rozšíří'))
                ->visible(fn ($get) => $failing($get) !== [])
                ->rules(fn ($get) => $failing($get) !== [] ? ['accepted'] : [])
                ->validationMessages(['accepted' => __('Nejdřív nastavte DNS, nebo potvrďte zapnutí i tak.')]),
        ];
    }
}
