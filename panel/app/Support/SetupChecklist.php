<?php

namespace App\Support;

use App\Actions\Mail\ManageMail;
use App\Actions\Mail\ManageMailHosting;
use App\Actions\Server\ManageOffsite;
use App\Models\NotificationChannel;
use App\Models\Setting;
use App\Models\Site;
use App\Services\Dns\Resolver;
use App\Services\Mail\Deliverability;
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

        // Mail the server sends or receives: the checks other servers make.
        $hosting = app(ManageMailHosting::class);
        // Through a relay (SMTP provider) the server's own PTR does not matter.
        $relay = Cache::remember('setup.relay', 600, fn () => (string) (rescue(fn () => app(ManageMail::class)->status(), [], report: false)['relay'] ?? ''));
        $sends = $relay === '' && ($hosting->installed() || Site::query()->where('mail_mode', 'send')->exists());
        $v4 = (string) config('panel.public_ipv4');
        if ($sends && $v4 !== '' && PublicAddress::host($v4)) {
            $host = ManageMailHosting::mailHost();
            $ptr = Cache::remember('setup.ptr', 600, fn () => app(Resolver::class)->reverse($v4));
            $add($ptr === $host, __('Reverzní záznam (PTR) :ip → :host', ['ip' => $v4, 'host' => $host]),
                __('Nastavuje ho poskytovatel serveru. Bez něj velcí poskytovatelé pošty zprávy odmítají.'), url('/mail'));
            if ($hosting->installed()) {
                $spf = Cache::remember('setup.host_spf', 600, fn () => app(Deliverability::class)->hostSpf()['ok']);
                $add($spf, __('SPF serveru pošty :host', ['host' => $host]),
                    __('TXT "v=spf1 a -all": přeposlaná pošta s ním projde u příjemce.'), url('/mail-domains'));
            }
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
