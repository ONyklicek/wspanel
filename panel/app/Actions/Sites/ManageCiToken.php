<?php

namespace App\Actions\Sites;

use App\Models\Site;

/**
 * Deploy token for CI pipelines. Separate from the webhook secret (which
 * the git host knows): it can deploy any ref or a pre-built artifact, so it
 * exists only when created, is shown once and stored as a hash.
 */
class ManageCiToken
{
    /** @return string the new token (shown once) */
    public function generate(Site $site): string
    {
        $token = 'wsp_'.bin2hex(random_bytes(32));
        $site->update([
            'ci_id' => $site->ci_id ?? bin2hex(random_bytes(16)),
            'ci_token_hash' => hash('sha256', $token),
        ]);

        return $token;
    }

    public function revoke(Site $site): void
    {
        $site->update(['ci_token_hash' => null]);
    }

    public static function check(Site $site, string $token): bool
    {
        return $site->ci_token_hash !== null && $token !== '' && hash_equals($site->ci_token_hash, hash('sha256', $token));
    }
}
