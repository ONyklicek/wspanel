<?php

namespace App\Http\Middleware;

use App\Actions\Clients\Impersonation;
use App\Models\Setting;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor sign-in, when the administrator requires it (Server → Moduly):
 * a user without it confirmed can only reach the profile to set it up, the
 * Fortify endpoints that do so, and sign out.
 */
class EnsureTwoFactor
{
    /** none | clients | all */
    public const SETTING = 'security.require_2fa';

    public const ALLOWED_ROUTES = ['wire.users.profile', 'logout', 'two-factor.enable', 'two-factor.confirm', 'two-factor.qr-code',
        'two-factor.secret-key', 'two-factor.recovery-codes', 'two-factor.regenerate-recovery-codes', 'impersonation.leave'];

    public static function requiredFor(User $user): bool
    {
        $policy = (string) (Setting::get(self::SETTING) ?? 'none');

        return $policy === 'all' || ($policy === 'clients' && ! $user->isAdmin());
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $user->two_factor_confirmed_at !== null || session()->has(Impersonation::SESSION_KEY)
            || ! self::requiredFor($user) || $request->routeIs(...self::ALLOWED_ROUTES) || $this->profileUpdate($request)) {
            return $next($request);
        }

        return $request->expectsJson()
            ? response()->json(['message' => 'Nejdřív si zapněte dvoufázové přihlášení v profilu.'], 403)
            : redirect()->route('wire.users.profile')->with('status', 'Správce vyžaduje dvoufázové přihlášení: zapněte ho v části Zabezpečení.');
    }

    /** Livewire round trips of the profile page itself. */
    private function profileUpdate(Request $request): bool
    {
        if (! $request->routeIs('*livewire.update')) {
            return false;
        }
        foreach ((array) $request->input('components', []) as $c) {
            $snapshot = json_decode((string) ($c['snapshot'] ?? ''), true);
            if (! str_contains((string) ($snapshot['memo']['path'] ?? ''), 'users/profile')) {
                return false;
            }
        }

        return true;
    }
}
