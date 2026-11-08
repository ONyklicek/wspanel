<?php

namespace App\Http\Middleware;

use App\Actions\Clients\Impersonation;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a client user whose client is suspended (or gone). Checked on
 * every request, so a suspension takes effect immediately, not at next login.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && ! $user->isAdmin() && ($user->client === null || $user->client->isSuspended())) {
            if (session()->has(Impersonation::SESSION_KEY)) {
                return $next($request);
            }
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => __('Účet je pozastavený. Kontaktujte správce.')]);
        }

        return $next($request);
    }
}
