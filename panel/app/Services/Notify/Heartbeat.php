<?php

namespace App\Services\Notify;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Watching from outside: a check at Healthchecks.io (hc-ping.com) that the
 * panel pings every few minutes. Everything fine: a plain ping; a serious
 * problem: /fail with what is wrong. When the server itself goes down (or
 * the panel with it) the pings stop, and Healthchecks.io says so: the one
 * failure the panel cannot report.
 *
 * Only hc-ping.com, never an address somebody typed (as with the webhooks).
 */
class Heartbeat
{
    /** A check's ping URL: by UUID, or by project ping key and slug. */
    public const URL_PATTERN = '#^https://hc-ping\.com/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[A-Za-z0-9_-]{22}/[a-z0-9-]{1,100})$#';

    public function __construct(private readonly Alerts $alerts) {}

    public static function url(): ?string
    {
        $url = Setting::get('heartbeat.url');

        return is_string($url) && preg_match(self::URL_PATTERN, $url) ? $url : null;
    }

    public static function configure(?string $url): void
    {
        Setting::put('heartbeat.url', $url !== null && $url !== '' ? $url : null);
        Setting::put('heartbeat.last', null);
    }

    /**
     * Pings the check; returns whether the ping went through.
     *
     * @return array{ok: bool, failing: list<string>}
     */
    public function ping(): array
    {
        $url = self::url();
        if ($url === null) {
            return ['ok' => false, 'failing' => []];
        }
        $failing = array_values(array_map(fn (array $a) => trim($a['title'].' '.$a['detail']),
            array_filter($this->alerts->current(), fn (array $a) => $a['level'] === 'danger')));
        try {
            $request = Http::timeout(10)->retry(2, 2000, throw: false);
            $response = $failing === []
                ? $request->get($url)
                : $request->withBody(mb_substr(implode("\n", $failing), 0, 9000), 'text/plain')->post($url.'/fail');
            $ok = $response->successful();
            Setting::put('heartbeat.last', ['at' => now()->toIso8601String(), 'ok' => $ok, 'failing' => count($failing),
                'error' => $ok ? null : 'HTTP '.$response->status()]);

            return ['ok' => $ok, 'failing' => $failing];
        } catch (Throwable $e) {
            Setting::put('heartbeat.last', ['at' => now()->toIso8601String(), 'ok' => false, 'failing' => count($failing), 'error' => mb_substr($e->getMessage(), 0, 300)]);

            return ['ok' => false, 'failing' => $failing];
        }
    }
}
