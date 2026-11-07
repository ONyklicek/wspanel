<?php

namespace App\Actions\Clients;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * An administrator looking at the panel as one of a client's users sees,
 * and may do, exactly what that user would. The way back is kept in the session.
 */
class Impersonation
{
    public const SESSION_KEY = 'impersonator_id';

    public function start(User $admin, User $target): void
    {
        abort_unless($admin->isAdmin() && ! $target->isAdmin(), 403);
        Log::notice('panel: impersonation started', ['admin' => $admin->email, 'as' => $target->email]);
        session()->put(self::SESSION_KEY, $admin->id);
        Auth::login($target);
        session()->regenerate();
        session()->put(self::SESSION_KEY, $admin->id);
    }

    public function active(): bool
    {
        return session()->has(self::SESSION_KEY);
    }

    public function leave(): bool
    {
        $adminId = session()->pull(self::SESSION_KEY);
        $admin = $adminId ? User::find($adminId) : null;
        if ($admin === null || ! $admin->isAdmin()) {
            return false;
        }
        Log::notice('panel: impersonation ended', ['admin' => $admin->email, 'as' => Auth::user()?->email]);
        Auth::login($admin);
        session()->regenerate();

        return true;
    }
}
