<?php

namespace App\Services\Webhooks;

use Illuminate\Http\Request;

/**
 * Verifies a webhook against the site's secret and normalizes the payload.
 *
 * - GitHub, Bitbucket: X-Hub-Signature-256 (Bitbucket also X-Hub-Signature) = "sha256=" + HMAC-SHA256(body)
 * - Gitea, Forgejo:    X-Gitea-Signature / X-Forgejo-Signature = HMAC-SHA256(body) hex
 * - GitLab:            X-Gitlab-Token = the secret itself
 *
 * All comparisons are constant-time. Returns null when no signature matches.
 */
class WebhookVerifier
{
    public function verify(Request $request, string $secret): ?PushEvent
    {
        $body = $request->getContent();
        $hmac = hash_hmac('sha256', $body, $secret);

        $provider = match (true) {
            $this->matches($request->header('X-Hub-Signature-256'), 'sha256='.$hmac) => $request->hasHeader('X-GitHub-Event') ? 'github' : 'bitbucket',
            $this->matches($request->header('X-Hub-Signature'), 'sha256='.$hmac) => 'bitbucket', // never the legacy SHA-1 form
            $this->matches($request->header('X-Gitea-Signature'), $hmac) => 'gitea',
            $this->matches($request->header('X-Forgejo-Signature'), $hmac) => 'forgejo',
            $this->matches($request->header('X-Gitlab-Token'), $secret) => 'gitlab',
            default => null,
        };
        if ($provider === null) {
            return null;
        }

        $payload = json_decode($body, true);
        $payload = is_array($payload) ? $payload : [];
        $event = (string) ($request->header('X-GitHub-Event')
            ?? $request->header('X-Gitea-Event')
            ?? $request->header('X-Forgejo-Event')
            ?? $request->header('X-Gitlab-Event')
            ?? $request->header('X-Event-Key')
            ?? '');

        $isPing = $event === 'ping' || ($provider === 'gitlab' && $event === 'System Hook');
        $isPush = in_array(strtolower($event), ['push', 'push hook', 'repo:push'], true);

        $ref = $payload['ref'] ?? null;
        $branch = is_string($ref) && str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : null;
        if ($provider === 'bitbucket' && $branch === null) { // Bitbucket Cloud push payload
            $change = $payload['push']['changes'][0]['new'] ?? null;
            if (($change['type'] ?? null) === 'branch') {
                $branch = $change['name'] ?? null;
            }
        }
        $commit = $payload['after'] ?? $payload['checkout_sha'] ?? ($payload['push']['changes'][0]['new']['target']['hash'] ?? null);

        return new PushEvent(
            provider: $provider,
            event: $event,
            isPush: $isPush,
            isPing: $isPing,
            branch: is_string($branch) ? $branch : null,
            commit: is_string($commit) && preg_match('/^[0-9a-f]{7,64}$/', $commit) ? $commit : null,
        );
    }

    private function matches(?string $given, string $expected): bool
    {
        return is_string($given) && $given !== '' && hash_equals($expected, $given);
    }
}
