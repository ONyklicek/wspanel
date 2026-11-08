<?php

namespace App\Actions\Sites;

use App\Actions\ManagePanelAccess;
use App\Models\Site;
use App\Support\PublicAddress;

class ManageWebhook
{
    /** Enables the webhook, creating its opaque id and secret on first use. */
    public function enable(Site $site): Site
    {
        $site->update([
            'webhook_id' => $site->webhook_id ?? bin2hex(random_bytes(16)),
            'webhook_secret' => $site->webhook_secret ?? bin2hex(random_bytes(32)),
            'webhook_enabled' => true,
        ]);

        return $site;
    }

    public function disable(Site $site): Site
    {
        $site->update(['webhook_enabled' => false, 'deploy_requested_at' => null]);

        return $site;
    }

    /** New secret and a new URL: the old ones stop working immediately. */
    public function rotate(Site $site): Site
    {
        $site->update(['webhook_id' => bin2hex(random_bytes(16)), 'webhook_secret' => bin2hex(random_bytes(32)), 'deploy_requested_at' => null]);

        return $site;
    }

    /**
     * The webhook on the panel itself: for a git host that reaches the panel
     * (a public panel, or Gitea / GitLab in the same local network).
     */
    public static function url(Site $site): ?string
    {
        return $site->webhook_id ? rtrim((string) config('app.url'), '/').'/hooks/deploy/'.$site->webhook_id : null;
    }

    /**
     * The webhook for a git host on the internet (GitHub.com): the panel when
     * the internet reaches it; otherwise (a panel on the local network, or one
     * limited to some addresses) the site's own public domain, whose edge
     * passes exactly this path on to the panel. Null when neither is
     * reachable: the panel then watches the repository instead.
     */
    public static function publicUrl(Site $site): ?string
    {
        if (! $site->webhook_id) {
            return null;
        }
        if (self::panelReachable()) {
            return self::url($site);
        }
        if (PublicAddress::host($site->domain)) {
            return $site->url('/.well-known/wspanel-deploy/'.$site->webhook_id);
        }

        return null;
    }

    /** Whether a webhook can reach this site at all (through the panel or the site's domain). */
    public static function reachable(Site $site): bool
    {
        return self::panelReachable() || PublicAddress::host($site->domain);
    }

    /** The panel on a public address, and its access list (if any) lets webhooks through. */
    public static function panelReachable(): bool
    {
        $access = app(ManagePanelAccess::class)->current();

        return PublicAddress::url((string) config('app.url')) && ($access['allow'] === [] || ($access['public_hooks'] ?? true));
    }
}
