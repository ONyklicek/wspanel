<?php

use App\Http\Controllers\CiDeployController;
use App\Http\Controllers\DeployWebhookController;
use App\Http\Middleware\EnsureAccountActive;
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
