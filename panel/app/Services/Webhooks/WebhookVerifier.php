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
        $isPush = in_array(strtolower($event), ['push', 'push hook', 'tag push hook', 'repo:push'], true);

        $ref = $payload['ref'] ?? null;
        $branch = is_string($ref) && str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : null;
        $tag = is_string($ref) && str_starts_with($ref, 'refs/tags/') ? substr($ref, 10) : null;
        if ($provider === 'bitbucket' && $branch === null) { // Bitbucket Cloud push payload
            $change = $payload['push']['changes'][0]['new'] ?? null;
            match ($change['type'] ?? null) {
                'branch' => $branch = $change['name'] ?? null,
                'tag' => $tag = $change['name'] ?? null,
                default => null,
            };
        }
        // A published release (GitHub, Gitea, Forgejo) deploys its tag.
        if (strtolower($event) === 'release' && ($payload['action'] ?? null) === 'published') {
            $isPush = true;
            $tag = $payload['release']['tag_name'] ?? null;
        }
        if (strtolower($event) === 'release hook' && ($payload['action'] ?? null) === 'create') { // GitLab
            $isPush = true;
            $tag = $payload['tag'] ?? null;
        }
        $tag = is_string($tag) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,99}$/', $tag) && ! str_contains($tag, '..') ? $tag : null;
        $commit = $payload['after'] ?? $payload['checkout_sha'] ?? ($payload['push']['changes'][0]['new']['target']['hash'] ?? null);

        $delivery = $request->header('X-GitHub-Delivery')
            ?? $request->header('X-Gitea-Delivery')
            ?? $request->header('X-Forgejo-Delivery')
            ?? $request->header('X-Gitlab-Event-UUID')
            ?? $request->header('X-Request-UUID');

        return new PushEvent(
            provider: $provider,
            event: $event,
            isPush: $isPush,
            isPing: $isPing,
            branch: is_string($branch) ? $branch : null,
            commit: is_string($commit) && preg_match('/^[0-9a-f]{7,64}$/', $commit) ? $commit : null,
            deliveryId: is_string($delivery) && preg_match('/^[A-Za-z0-9{}-]{8,64}$/', $delivery) ? $delivery : null,
            tag: $tag,
        );
    }

    private function matches(?string $given, string $expected): bool
    {
        return is_string($given) && $given !== '' && hash_equals($expected, $given);
    }
}
