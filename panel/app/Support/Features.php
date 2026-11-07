<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Optional parts of the panel, chosen at installation (install.sh) and
 * switched later on the Moduly page. The panel's choice wins over the
 * installer's default once made.
 */
final class Features
{
    public static function clients(): bool
    {
        return (bool) Setting::get('feature.clients', (bool) config('panel.clients'));
    }

    public static function setClients(bool $on): void
    {
        Setting::put('feature.clients', $on);
    }
}
