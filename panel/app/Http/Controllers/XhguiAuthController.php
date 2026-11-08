<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Edge forward_auth for a site's XHGui, which lives on the site's own domain
 * (/_wsp_xhgui/). The edge names the domain in this URL itself (its block is
 * the site's), so nothing here is parsed out of a path a visitor sent.
 *
 *  - ?wsp_token=… (a one-time link from the panel, for this domain): sets a
 *    cookie signed for this domain and path, and sends the browser on.
 *  - the cookie: 204 while it is valid.
 *
 * Outside the web group on purpose: no panel session, no panel cookie is
 * ever set on a site's domain.
 */
class XhguiAuthController extends Controller
{
    public const COOKIE = 'wsp_xhgui';

    public const TTL = 8 * 3600;

    public function __invoke(Request $request, string $domain): Response
    {
        $domain = strtolower($domain);
        $site = Site::acrossClients()->where('domain', $domain)->first();
        if ($site === null || $site->xhprof === null) {
            return $this->deny();
        }
        $uri = (string) $request->header('X-Forwarded-Uri', '/');
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        if (is_string($query['wsp_token'] ?? null)) {
            if (Cache::pull('xhgui-token:'.$query['wsp_token']) !== $domain) {
                return $this->deny();
            }
            $expires = time() + self::TTL;
            $value = $expires.'.'.self::sign($domain, $expires);
            $path = (string) parse_url($uri, PHP_URL_PATH);

            return response('', 302, [
                'Location' => str_starts_with($path, '/_wsp_xhgui/') ? $path : '/_wsp_xhgui/',
                'Set-Cookie' => self::COOKIE."={$value}; Path=/_wsp_xhgui/; Max-Age=".self::TTL.'; Secure; HttpOnly; SameSite=Lax',
                'Cache-Control' => 'no-store',
            ]);
        }

        return self::valid($request->header('Cookie', ''), $domain) ? response()->noContent() : $this->deny();
    }

    /** A one-time sign-in link for the site's XHGui (valid 2 minutes). */
    public static function link(Site $site): string
    {
        $token = bin2hex(random_bytes(24));
        Cache::put('xhgui-token:'.$token, $site->domain, 120);

        return $site->url('/_wsp_xhgui/?wsp_token='.$token);
    }

    public static function sign(string $domain, int $expires): string
    {
        return hash_hmac('sha256', $domain.'|'.$expires, 'xhgui|'.config('app.key'));
    }

    private static function valid(string $cookieHeader, string $domain): bool
    {
        if (! preg_match('/(?:^|;\s*)'.self::COOKIE.'=(\d+)\.([a-f0-9]{64})(?:;|$)/', $cookieHeader, $m)) {
            return false;
        }

        return (int) $m[1] > time() && hash_equals(self::sign($domain, (int) $m[1]), $m[2]);
    }

    private function deny(): Response
    {
        return response(__('<!doctype html><meta charset="utf-8"><title>XHGui</title><p style="font-family:system-ui;margin:3rem">Profily otevřete z panelu: detail webu → Profilování → Otevřít XHGui.</p>'), 403, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
