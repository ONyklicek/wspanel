<?php

namespace App\Actions\Sites;

use App\Models\Deployment;
use App\Models\Site;
use App\Services\GitHub\GitHubException;
use App\Support\CurrentClient;

/**
 * Every deploy of a site linked to GitHub appears there as a deployment of
 * its commit: in progress, then success or failure, with links to the site
 * and to the deploy log in the panel. Runs every minute (panel:sync).
 */
class ReportGitHubDeployments
{
    /** Deploy status => GitHub deployment state. */
    public const STATES = ['running' => 'in_progress', 'success' => 'success', 'failed' => 'failure', 'rolled_back' => 'failure'];

    public function __construct(private readonly ManageDeploys $deploys) {}

    public function all(): int
    {
        $reported = 0;
        $sites = CurrentClient::unrestricted(fn () => Site::query()->whereNotNull('github_repo')->whereNotNull('github_connection_id')->with('githubConnection')->get());
        foreach ($sites as $site) {
            $reported += $this->site($site);
        }

        return $reported;
    }

    public function site(Site $site): int
    {
        if ($site->githubConnection === null || $site->github_repo === null) {
            return 0;
        }
        rescue(fn () => $this->deploys->history($site), report: false); // brings new deploys into the table
        $pending = Deployment::query()->where('site_id', $site->id)
            ->where('started_at', '>=', $site->github_linked_at)
            ->where(fn ($q) => $q->whereNull('github_status')->orWhereColumn('github_status', '!=', 'status'))
            ->where(fn ($q) => $q->whereNull('github_status')->orWhere('github_status', '!=', 'skipped'))
            ->orderBy('started_at')->get();

        $gh = $site->githubConnection->client();
        $done = 0;
        foreach ($pending as $d) {
            $ref = $d->commit ?: $d->ref;
            try {
                if ($d->github_deployment_id === null) {
                    if ($ref === null) {
                        $d->update(['github_status' => 'skipped']);

                        continue;
                    }
                    $d->github_deployment_id = $gh->createDeployment($site->github_repo, $ref, $site->github_environment ?? ManageGitHub::ENVIRONMENT, 'WSPanel: '.$site->domain.' '.($d->ref ?? ''));
                    $d->save();
                }
                $gh->deploymentStatus($site->github_repo, $d->github_deployment_id, self::STATES[$d->status] ?? 'error', $site->url(),
                    route('wire.sites.deploy', ['record' => $site->id]), $d->statusLabel().($d->error ? ': '.$d->error : ''));
                $d->update(['github_status' => $d->status]);
                $done++;
            } catch (GitHubException $e) {
                // 422: GitHub does not know the commit (an artifact built elsewhere) – nothing to report against.
                if ($e->status === 422 && $d->github_deployment_id === null) {
                    $d->update(['github_status' => 'skipped']);

                    continue;
                }
                report($e);

                return $done; // token or GitHub down: try again next minute
            }
        }

        return $done;
    }
}
