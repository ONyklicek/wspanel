<?php

namespace App\Support;

use App\Actions\Server\ManageOffsite;
use App\Models\NotificationChannel;
use App\Models\Setting;
use App\Models\Site;
use App\Services\Dns\ServerDns;
use Illuminate\Support\Facades\Cache;

/**
 * The steps from a fresh install to a server that is safe to run (the
 * administrator's, on the Overview): each one done or not, and where to do
 * it. Gone once all are done, or when the administrator hides it.
 */
final class SetupChecklist
{
    public const DISMISSED = 'setup_checklist.dismissed';

    /** @return list<array{done: bool, title: string, detail: string, url: string}> */
    public static function steps(): array
    {
        $steps = [];
        $add = function (bool $done, string $title, string $detail, string $url) use (&$steps) {
            $steps[] = compact('done', 'title', 'detail', 'url');
        };
        $user = request()->user();

        $add(Site::query()->exists(), __('Založit první web'), __('Doména, typ aplikace a na čem poběží.'), url('/sites/create'));
        $add($user?->two_factor_confirmed_at !== null, __('Zapnout dvoufázové přihlášení'), __('Ochrání účet správce, i když heslo unikne.'), url('/users/profile'));
        $add(NotificationChannel::query()->exists(), __('Nastavit notifikace'), __('E-mail, Slack nebo webhook, když web spadne nebo dojde místo na disku.'), url('/notification-channels'));
        $add(Cache::remember('setup.offsite', 60, fn () => app(ManageOffsite::class)->enabled()), __('Zálohy mimo server'), __('Kopie záloh do S3, na FTP nebo NAS, aby přežily i ztrátu serveru.'), url('/offsite'));

        // The server's own records in public DNS (its name, the mail server,
        // the reverse record, its nameservers): one step until all are right.
        $dns = app(ServerDns::class)->cached();
        if ($dns !== []) {
            $failing = array_filter($dns, fn (array $r) => ! $r['ok']);
            $add($failing === [], __('DNS záznamy serveru'),
                $failing === [] ? __('Adresa panelu, server pošty, PTR a SPF platí.')
                    : __('Chybí nebo nesedí: :list', ['list' => collect($failing)->map(fn ($r) => $r['type'].' '.$r['name'])->implode(', ')]),
                url('/system'));
        }

        return $steps;
    }

    /** Whether the Overview shows it: an administrator, not hidden, not all done. */
    public static function shown(): bool
    {
        if (CurrentClient::restricted() || Setting::get(self::DISMISSED)) {
            return false;
        }

        return collect(self::steps())->contains(fn (array $s) => ! $s['done']);
    }
}
