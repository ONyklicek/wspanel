<?php

namespace App\Support;

use App\Models\User;

/**
 * Whose resources the current request may see.
 *
 * Null means "no restriction": an admin, a console command, the scheduler.
 * A client user always resolves to an id; one without a client resolves to 0,
 * which matches nothing, so a broken account sees nothing rather than everything.
 */
final class CurrentClient
{
    private static int $unrestricted = 0;

    public static function id(): ?int
    {
        if (self::$unrestricted > 0) {
            return null;
        }
        $user = auth()->user();
        if (! $user instanceof User || $user->isAdmin()) {
            return null;
        }

        return (int) ($user->client_id ?? 0);
    }

    public static function restricted(): bool
    {
        return self::id() !== null;
    }

    /**
     * Runs $callback past the client scope: mirroring the server's state
     * (services, databases, cron jobs) must see and attribute every row,
     * even when a client's action triggered the sync.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function unrestricted(callable $callback): mixed
    {
        self::$unrestricted++;
        try {
            return $callback();
        } finally {
            self::$unrestricted--;
        }
    }
}
