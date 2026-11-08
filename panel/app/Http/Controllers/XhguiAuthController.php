<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Edge forward_auth for a site's XHGui (/xhgui/<domain>/…): 204 when the
 * signed-in user may see that site (the owner scope decides, so a client
 * never opens another client's profiles), 403 otherwise; a guest goes to
 * the login and comes back.
 */
class XhguiAuthController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $uri = (string) $request->header('X-Forwarded-Uri', '/');
        if (! auth()->check()) {
            if (str_starts_with($uri, '/xhgui/')) {
                $request->session()->put('url.intended', rtrim((string) config('app.url'), '/').$uri);
            }

            return redirect()->to(route('login'));
        }
        $domain = preg_match('#^/xhgui/([a-z0-9.-]+)(/|$)#', strtolower($uri), $m) ? $m[1] : null;
        $site = $domain === null ? null : Site::query()->where('domain', $domain)->first();

        return $site !== null && $site->xhprof !== null
            ? response()->noContent()
            : response('Profilování tohoto webu není dostupné.', 403);
    }
}
