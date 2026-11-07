<?php

namespace App\Http\Controllers;

use App\Actions\Sites\DeployTrigger;
use App\Models\Site;
use App\Models\WebhookDelivery;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Webhooks\WebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public endpoint for git hosting webhooks (POST /hooks/deploy/{hook}).
 * Unknown or disabled hooks get a plain 404; nothing runs without a valid
 * signature; only pushes to the configured branch deploy.
 */
class DeployWebhookController
{
    public const MAX_BODY = 1024 * 1024;

    public function __invoke(Request $request, string $hook, WebhookVerifier $verifier, AgentClient $agent, DeployTrigger $trigger): JsonResponse
    {
        $site = preg_match('/^[a-f0-9]{32}$/', $hook)
            ? Site::query()->where('webhook_id', $hook)->where('webhook_enabled', true)->first()
            : null;
        if ($site === null || blank($site->webhook_secret)) {
            return response()->json(['error' => 'not found'], 404);
        }
        if (strlen($request->getContent()) > self::MAX_BODY) {
            return $this->record($site, $request, 'rejected', 'payload too large', 413);
        }

        $event = $verifier->verify($request, $site->webhook_secret);
        if ($event === null) {
            return $this->record($site, $request, 'rejected', 'invalid signature', 403);
        }
        $info = ['provider' => $event->provider, 'event' => $event->event, 'ref' => $event->branch, 'commit' => $event->commit];

        if ($event->isPing) {
            return $this->record($site, $request, 'ignored', 'ping', 200, $info);
        }
        if (! $event->isPush) {
            return $this->record($site, $request, 'ignored', "event {$event->event} is not a push", 202, $info);
        }

        try {
            $history = $agent->call('deploy.history', ['domain' => $site->domain]);
            $branch = $history['config']['branch'] ?? null;
            if ($branch === null) {
                return $this->record($site, $request, 'ignored', 'deploy is not configured', 202, $info);
            }
            if ($event->branch !== $branch) {
                return $this->record($site, $request, 'ignored', "push to {$event->branch}, deploying {$branch}", 202, $info);
            }
            $result = $trigger->request($site, $history);
        } catch (AgentException $e) {
            return $this->record($site, $request, 'failed', mb_substr($e->getMessage(), 0, 250), 502, $info);
        }

        return $this->record($site, $request, $result, $result === 'queued' ? 'a deploy is running; queued' : 'deploy started', 202, $info);
    }

    /** @param array<string, ?string> $info */
    private function record(Site $site, Request $request, string $result, string $message, int $status, array $info = []): JsonResponse
    {
        WebhookDelivery::create($info + ['site_id' => $site->id, 'result' => $result, 'message' => $message, 'ip' => $request->ip()]);
        // Keep the last 200 deliveries per site.
        $keep = WebhookDelivery::query()->where('site_id', $site->id)->latest('id')->skip(200)->value('id');
        if ($keep !== null) {
            WebhookDelivery::query()->where('site_id', $site->id)->where('id', '<=', $keep)->delete();
        }

        return response()->json(['result' => $result, 'message' => $message], $status);
    }
}
