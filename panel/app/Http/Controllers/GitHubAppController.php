<?php

namespace App\Http\Controllers;

use App\Actions\Sites\ManageGitHub;
use App\Livewire\Pages\IntegrationsPage;
use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\GitHubException;
use App\Support\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * "Connect GitHub" with a button: the administrator creates the panel's
 * GitHub App once from a manifest, users install it on their account or
 * organisation and come back. Every return from GitHub carries the state
 * this session sent, and an installation counts only when GitHub confirms
 * the signed-in GitHub user can access it.
 */
class GitHubAppController
{
    /** Posts the manifest to GitHub (a form the browser submits; GitHub requires a POST from the user). */
    public function create(Request $request): View
    {
        Gate::authorize('admin');
        $org = trim((string) $request->query('org', ''));
        abort_unless($org === '' || preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/', $org), 422);
        $state = $this->state($request, 'github.app_state');
        $this->rememberReturn($request);

        return view('github.create-app', [
            'action' => ($org === '' ? 'https://github.com/settings/apps/new' : "https://github.com/organizations/{$org}/settings/apps/new").'?state='.$state,
            'manifest' => json_encode(GitHubApp::manifest(), JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * GitHub came back with the new app: keep its credentials and go straight
     * on to installing it, so the administrator's first connection is one flow.
     */
    public function created(Request $request): RedirectResponse
    {
        Gate::authorize('admin');
        $this->checkState($request, 'github.app_state');

        return $this->finishCreating($request, (string) $request->query('code'));
    }

    /**
     * Finishing again with the code GitHub gave (valid an hour): the app
     * exists on GitHub, only the hand-over of its keys failed.
     */
    public function retry(Request $request): RedirectResponse
    {
        Gate::authorize('admin');
        $pending = $request->session()->get('github.app_pending');
        abort_unless(is_array($pending) && ($pending['until'] ?? 0) > time(), 410, __('Kód od GitHubu vypršel. Zadejte klíče aplikace ručně, nebo ji vytvořte znovu.'));

        return $this->finishCreating($request, (string) $pending['code']);
    }

    private function finishCreating(Request $request, string $code): RedirectResponse
    {
        try {
            $app = GitHubApp::createFromCode($code);
        } catch (GitHubException $e) {
            if ($e->status >= 500 || $e->status === 0) {
                // The app exists on GitHub; the code works for an hour.
                $request->session()->put('github.app_pending', ['code' => $code, 'until' => time() + 3300]);
            }
            Toast::error(__('GitHub aplikaci se nepodařilo dokončit'), $e->getMessage());

            return redirect(IntegrationsPage::url());
        }
        $request->session()->forget('github.app_pending');

        return redirect()->away($app->installUrl($this->state($request, 'github.connect_state')));
    }

    /** Sends the user to install the app (or to sign in, when they installed it before). */
    public function connect(Request $request): RedirectResponse
    {
        $app = GitHubApp::configured() ?? abort(404);
        $state = $this->state($request, 'github.connect_state');
        $this->rememberReturn($request);

        return redirect()->away($request->boolean('existing') ? $app->authorizeUrl($state) : $app->installUrl($state));
    }

    /** Back from GitHub: connect the installation(s) GitHub confirms are the user's. */
    public function callback(Request $request, ManageGitHub $github): RedirectResponse
    {
        $app = GitHubApp::configured() ?? abort(404);
        $this->checkState($request, 'github.connect_state');
        try {
            $userToken = $app->userToken((string) $request->query('code'));
            $mine = $app->userInstallations($userToken);
            $id = $request->query('installation_id');
            if ($id !== null) {
                abort_unless(ctype_digit((string) $id), 422);
                $mine = array_intersect_key($mine, [(int) $id => true]);
            }
            // Only what this GitHub user administers: deploy keys and webhooks are an admin's to add.
            foreach ($mine as $installation => $i) {
                $mine[$installation]['repositories'] = $app->administeredRepositories($userToken, $installation);
                if ($mine[$installation]['repositories'] === []) {
                    unset($mine[$installation]);
                }
            }
            if ($mine === []) {
                Toast::warning(__('Nic k připojení'), $request->query('setup_action') === 'request'
                    ? __('Instalaci musí schválit vlastník organizace na GitHubu; pak klikněte na Připojit znovu.')
                    : __('Aplikace :slug není nainstalovaná u účtu GitHub, kde spravujete aspoň jeden repozitář.', ['slug' => $app->slug]));
            } else {
                $connected = $github->connectInstallations($request->user(), $mine);
                Toast::success(__('GitHub připojen'), collect($connected)->map(fn ($c) => '@'.$c->login)->join(', '));
            }
        } catch (GitHubException $e) {
            Toast::error(__('GitHub se nepodařilo připojit'), $e->getMessage());
        }

        return $this->back($request);
    }

    /** Where to come back after GitHub: a page of this panel the user started from. */
    private function rememberReturn(Request $request): void
    {
        $to = (string) $request->query('return', '');
        // A path of this panel only: no scheme, no host, no protocol-relative //.
        if ($to !== '' && str_starts_with($to, '/') && ! str_starts_with($to, '//') && ! str_contains($to, '\\')) {
            $request->session()->put('github.return', $to);
        }
    }

    private function back(Request $request): RedirectResponse
    {
        return redirect($request->session()->pull('github.return') ?? IntegrationsPage::url());
    }

    private function state(Request $request, string $key): string
    {
        $state = Str::random(40);
        $request->session()->put($key, $state);

        return $state;
    }

    /** The return must carry the state this session sent; used once. */
    private function checkState(Request $request, string $key): void
    {
        $expected = $request->session()->pull($key);
        abort_unless(is_string($expected) && hash_equals($expected, (string) $request->query('state')), 403, __('Neplatný nebo prošlý návrat z GitHubu. Zkuste to znovu.'));
    }
}
