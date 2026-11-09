<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The web tier hands the panel its requests under an internal host name
 * (wspanel-panel.internal). URL::forceRootUrl() covers the links the panel
 * builds, not what Laravel reads off the request itself: the address kept
 * for "back where you were" after signing in, request()->getHost(). Those
 * would send the browser to the internal name. The request is given the
 * address users open (APP_URL) instead.
 */
class UsePanelAddress
{
    public const INTERNAL_HOST = 'wspanel-panel.internal';

    public function handle(Request $request, Closure $next): Response
    {
        $url = parse_url((string) config('app.url'));
        if (strcasecmp($request->getHost(), self::INTERNAL_HOST) === 0 && isset($url['host'])) {
            $https = ($url['scheme'] ?? 'https') === 'https';
            $port = (int) ($url['port'] ?? ($https ? 443 : 80));
            $request->headers->set('HOST', $url['host'].(isset($url['port']) ? ':'.$url['port'] : ''));
            $request->server->set('HTTP_HOST', $request->headers->get('HOST'));
            $request->server->set('SERVER_NAME', $url['host']);
            $request->server->set('SERVER_PORT', $port);
            $request->server->set('HTTPS', $https ? 'on' : 'off');
        }

        return $next($request);
    }
}
