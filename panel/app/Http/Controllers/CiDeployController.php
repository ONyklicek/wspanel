<?php

namespace App\Http\Controllers;

use App\Actions\Sites\ManageDeploys;
use App\Models\Site;
use App\Services\Agent\AgentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Deploy API for CI (GitHub Actions, GitLab CI…), authenticated with the
 * site's deploy token (Authorization: Bearer …):
 *
 *   POST /api/deploy/{hook}            {"ref": "v1.2.3"}       deploy a tag/branch/commit
 *   POST /api/deploy/{hook}/artifact   multipart artifact=@build.tar.gz [ref, commit]
 *                                       deploy a release built in CI (no build on the server)
 *   GET  /api/deploy/{hook}/{release}  status of a deploy (for the pipeline to wait on)
 */
class CiDeployController
{
    public const MAX_ARTIFACT_KB = 512 * 1024;

    public function trigger(Request $request, string $hook, ManageDeploys $deploys): JsonResponse
    {
        $site = $this->site($request, $hook);
        $data = $request->validate(['ref' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', 'not_regex:/\.\./']]);

        return $this->start($site, $deploys, array_filter(['ref' => $data['ref'] ?? null]));
    }

    public function artifact(Request $request, string $hook, ManageDeploys $deploys): JsonResponse
    {
        $site = $this->site($request, $hook);
        $data = $request->validate([
            'artifact' => ['required', 'file', 'max:'.self::MAX_ARTIFACT_KB],
            'ref' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', 'not_regex:/\.\./'],
            'commit' => ['nullable', 'string', 'regex:/^[0-9a-f]{7,64}$/'],
        ]);
        $file = $request->file('artifact');
        if (bin2hex((string) file_get_contents($file->getRealPath(), length: 2)) !== '1f8b') {
            return response()->json(['error' => 'artifact must be a .tar.gz'], 422);
        }
        $name = Str::lower(Str::ulid()).'.tar.gz';
        $target = rtrim((string) config('agent.artifact_dir'), '/').'/'.$name;
        if (! @rename($file->getRealPath(), $target) && ! @copy($file->getRealPath(), $target)) {
            return response()->json(['error' => 'could not store the artifact'], 500);
        }
        @chmod($target, 0600);

        $response = $this->start($site, $deploys, array_filter(['artifact' => $name, 'ref' => $data['ref'] ?? null, 'commit' => $data['commit'] ?? null]));
        if ($response->getStatusCode() >= 300) {
            @unlink($target);
        }

        return $response;
    }

    public function status(Request $request, string $hook, string $release, ManageDeploys $deploys): JsonResponse
    {
        $site = $this->site($request, $hook);
        abort_unless(preg_match('/^\d{8}T\d{6}Z$/', $release) === 1, 404);
        try {
            $deploy = collect($deploys->history($site)['deploys'] ?? [])->firstWhere('id', $release);
        } catch (AgentException) {
            return response()->json(['error' => 'agent unavailable'], 503);
        }
        abort_if($deploy === null, 404);

        return response()->json([
            'release' => $release,
            'status' => $deploy['status'],
            'ref' => $deploy['ref'] ?? null,
            'commit' => $deploy['commit'] ?? null,
            'error' => $deploy['error'] ?? null,
            'url' => $site->url(),
        ]);
    }

    /** @param  array{ref?: string, artifact?: string, commit?: string}  $options */
    private function start(Site $site, ManageDeploys $deploys, array $options): JsonResponse
    {
        try {
            $release = $deploys->start($site, $options);
        } catch (AgentException $e) {
            $status = $e->errorCode === 'conflict' ? 409 : 502;

            return response()->json(['error' => $status === 409 ? 'a deploy is already running' : 'deploy could not start'], $status);
        }

        return response()->json([
            'release' => $release,
            'status' => 'running',
            'status_url' => route('api.deploy.status', ['hook' => $site->webhook_id, 'release' => $release]),
        ], 202);
    }

    /** Same token as the webhook secret; unknown, disabled or wrong token = 404. */
    private function site(Request $request, string $hook): Site
    {
        $site = preg_match('/^[a-f0-9]{32}$/', $hook)
            ? Site::query()->where('webhook_id', $hook)->where('webhook_enabled', true)->first()
            : null;
        $token = (string) $request->bearerToken();
        abort_if($site === null || blank($site->webhook_secret) || $token === '' || ! hash_equals($site->webhook_secret, $token), 404);

        return $site;
    }
}
