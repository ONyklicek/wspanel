<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Edge forward_auth for tools served next to the panel (Mailpit): 204 for a
 * logged-in panel user, otherwise a redirect to the panel login that returns
 * to the tool afterwards.
 */
class ForwardAuthController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (auth()->check()) {
            return response()->noContent();
        }

        $uri = (string) $request->header('X-Forwarded-Uri', '/');
        if (str_starts_with($uri, '/mailpit')) {
            $request->session()->put('url.intended', rtrim((string) config('app.url'), '/').$uri);
        }

        return redirect()->to(route('login'));
    }
}
