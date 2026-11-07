<?php

namespace App\Actions\Sites;

use App\Models\Site;

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

    public static function url(Site $site): ?string
    {
        return $site->webhook_id ? rtrim(config('app.url'), '/').'/hooks/deploy/'.$site->webhook_id : null;
    }
}
