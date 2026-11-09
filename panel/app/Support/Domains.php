<?php

namespace App\Support;

use App\Enums\SiteMode;

/** Domain and runtime parameter helpers shared by the UI and the actions. */
final class Domains
{
    /** Same rule as the agent's validator (punycode, lowercase, 2+ labels). */
    public const REGEX = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+([a-z]{2,63}|xn--[a-z0-9-]{1,59})$/';

    /** TLDs no public CA can issue for: same list as the agent. */
    public const INTERNAL_TLDS = ['localhost', 'local', 'lan', 'internal', 'intranet', 'test', 'home', 'corp', 'private', 'home.arpa', 'example', 'invalid'];

    public static function normalize(?string $domain): string
    {
        $domain = strtolower(trim((string) $domain));
        $domain = (string) preg_replace('#^https?://#', '', $domain);
        $domain = rtrim(explode('/', $domain)[0], '.');
        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7f]/', $domain)) {
            $domain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain;
        }

        return $domain;
    }

    public static function isValid(?string $domain): bool
    {
        return preg_match(self::REGEX, self::normalize($domain)) === 1;
    }

    public static function isInternal(string $domain): bool
    {
        foreach (self::INTERNAL_TLDS as $tld) {
            if (str_ends_with($domain, '.'.$tld)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Agent params for the chosen runtime: only the version field the mode uses.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public static function runtimeParams(array $data): array
    {
        $params = ['mode' => (string) $data['mode'], 'framework' => (string) $data['framework']];
        if ($data['mode'] === SiteMode::Classic->value) {
            $params['php_version'] = (string) $data['php_version'];
        }
        if ($data['mode'] === SiteMode::Node->value) {
            $params['node_version'] = (string) $data['node_version'];
        }
        if ($data['mode'] === SiteMode::Python->value) {
            $params['python_version'] = (string) $data['python_version'];
            if (($data['python_app'] ?? '') !== '') {
                $params['python_app'] = (string) $data['python_app'];
            }
        }

        return $params;
    }
}
