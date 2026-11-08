<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer API token (Authorization: Bearer wspa_…). The request then runs as
 * the token's user, so every owner scope applies; a suspended client's token
 * is refused, and a read-only token only reads.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = ApiToken::findValid((string) $request->bearerToken());
        $user = $token?->user;
        if ($token === null || $user === null || (! $user->isAdmin() && ($user->client === null || $user->client->isSuspended()))) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        if (! $token->can_write && ! $request->isMethodSafe()) {
            return response()->json(['error' => 'this token is read-only'], 403);
        }
        auth()->setUser($user);
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
