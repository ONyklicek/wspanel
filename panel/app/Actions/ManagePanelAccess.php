<?php

namespace App\Actions;

use App\Models\Setting;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use InvalidArgumentException;

/**
 * Who may open the panel (and phpMyAdmin, Adminer, Mailpit on its domain).
 * Deploy webhooks and the CI API can stay public; loopback is always allowed.
 */
class ManagePanelAccess
{
    public function __construct(private readonly AgentClient $agent) {}

    /** @return array{allow: list<string>, public_hooks: bool} */
    public function current(): array
    {
        return Setting::get('panel_access', ['allow' => [], 'public_hooks' => true]);
    }

    /**
     * @param  list<string>  $allow
     *
     * @throws AgentException|InvalidArgumentException
     */
    public function set(array $allow, bool $publicHooks, ?string $clientIp): void
    {
        $allow = array_values(array_unique(array_filter(array_map('trim', $allow))));
        if ($allow !== [] && $clientIp !== null && ! self::covers($allow, $clientIp)) {
            throw new InvalidArgumentException(__('Seznam nezahrnuje vaši adresu :client_ip, po uložení byste se do panelu nedostali.', ['client_ip' => $clientIp]));
        }
        $this->agent->call('panel.access', ['allow' => $allow, 'public_hooks' => $publicHooks]);
        Setting::put('panel_access', ['allow' => $allow, 'public_hooks' => $publicHooks]);
    }

    /** @param  list<string>  $allow */
    public static function covers(array $allow, string $ip): bool
    {
        foreach ($allow as $entry) {
            if (! str_contains($entry, '/')) {
                if (inet_pton($entry) === inet_pton($ip)) {
                    return true;
                }

                continue;
            }
            [$net, $bits] = explode('/', $entry, 2);
            $a = @inet_pton($net);
            $b = @inet_pton($ip);
            if ($a === false || $b === false || strlen($a) !== strlen($b)) {
                continue;
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0 || (ord($a[$bytes]) >> (8 - $rest)) === (ord($b[$bytes]) >> (8 - $rest))) {
                return true;
            }
        }

        return false;
    }
}
