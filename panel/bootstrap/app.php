<?php

use App\Http\Controllers\Api\V1Controller;
use App\Http\Controllers\CiDeployController;
use App\Http\Controllers\DeployWebhookController;
use App\Http\Controllers\XhguiAuthController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureTwoFactor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Git hosting webhooks: public, outside the "web" group (no session,
            // no CSRF), authenticated by the payload signature.
            Route::post('/hooks/deploy/{hook}', DeployWebhookController::class)
                ->middleware('throttle:webhooks')
                ->name('hooks.deploy');

            // A site's XHGui sign-in (edge forward_auth from the site's domain): no
            // session, so no panel cookie is ever set on a site's domain.
            Route::get('/auth/xhgui/{domain}', XhguiAuthController::class)
                ->where('domain', '[a-z0-9.-]+')
                ->middleware('throttle:120,1')
                ->name('auth.xhgui');

            // REST API v1: personal tokens (Authorization: Bearer wspa_…), no session.
            Route::middleware(['throttle:api-v1', AuthenticateApiToken::class])->prefix('api/v1')->name('api.v1.')->group(function () {
                Route::get('/me', [V1Controller::class, 'me'])->name('me');
                Route::get('/sites', [V1Controller::class, 'sites'])->name('sites');
                Route::get('/sites/{domain}', [V1Controller::class, 'showSite'])->name('sites.show');
                Route::get('/sites/{domain}/deploys', [V1Controller::class, 'deploys'])->name('sites.deploys');
                Route::post('/sites/{domain}/deploy', [V1Controller::class, 'deploy'])->name('sites.deploy');
                Route::post('/sites/{domain}/restart', [V1Controller::class, 'restart'])->name('sites.restart');
                Route::get('/databases', [V1Controller::class, 'databases'])->name('databases');
                Route::get('/mail/domains', [V1Controller::class, 'mailDomains'])->name('mail.domains');
                Route::get('/dns/zones', [V1Controller::class, 'dnsZones'])->name('dns.zones');
            });

            // Deploy API for CI pipelines: bearer token, no session.
            Route::middleware('throttle:ci-deploy')->prefix('api/deploy/{hook}')->group(function () {
                Route::post('/', [CiDeployController::class, 'trigger'])->name('api.deploy.trigger');
                Route::post('/artifact', [CiDeployController::class, 'artifact'])->name('api.deploy.artifact');
                Route::get('/{release}', [CiDeployController::class, 'status'])->name('api.deploy.status');
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // A suspended client is signed out on its next request (Livewire's too).
        $middleware->appendToGroup('web', EnsureAccountActive::class);
        // Two-factor sign-in, when the administrator requires it.
        $middleware->appendToGroup('web', EnsureTwoFactor::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
