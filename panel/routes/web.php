<?php

use App\Actions\Clients\Impersonation;
use App\Actions\Sites\ManageTls;
use App\Http\Controllers\AppleMailProfileController;
use App\Http\Controllers\DownloadBackupController;
use App\Http\Controllers\DownloadSiteBackupController;
use App\Http\Controllers\DownloadSiteFileController;
use App\Http\Controllers\ForwardAuthController;
use App\Http\Controllers\GitHubAppController;
use App\Http\Controllers\OpenDatabaseServerToolController;
use App\Http\Controllers\OpenDatabaseToolController;
use App\Http\Controllers\OpenWebmailController;
use App\Http\Controllers\XhguiAuthController;
use App\Http\Middleware\SetLocale;
use App\Livewire\Pages\HelpPage;
use App\Models\Site;
use Illuminate\Support\Facades\Route;

// Edge forward_auth for Mailpit (outside the panel group: answers 204 or redirects).
Route::get('/auth/check', ForwardAuthController::class)->name('auth.check');

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/backups/{backup}/download', DownloadBackupController::class)->name('backups.download');
    Route::get('/sites/{site}/backups/{file}', DownloadSiteBackupController::class)
        ->where('file', '[A-Za-z0-9._-]+\\.tar\\.gz')
        ->name('site-backups.download');
    Route::get('/sites/{site}/files/download', DownloadSiteFileController::class)->middleware('throttle:60,1')->name('site-files.download');
    Route::get('/db-open/{database}/{tool}', OpenDatabaseToolController::class)
        ->whereIn('tool', ['phpmyadmin', 'adminer'])
        ->middleware('throttle:30,1')
        ->name('dbtools.open');
    Route::get('/db-open-server/{engine}/{tool}', OpenDatabaseServerToolController::class)
        ->whereIn('engine', ['mariadb', 'postgresql'])
        ->whereIn('tool', ['phpmyadmin', 'adminer'])
        ->middleware(['can:admin', 'throttle:30,1'])
        ->name('dbtools.server');
    // The signed-in user's language (user menu).
    Route::get('/help/img/{file}', function (string $file) {
        $path = HelpPage::imagePath($file) ?? abort(404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=86400']);
    })->name('help.image');
    Route::get('/github/app/create', [GitHubAppController::class, 'create'])->name('github.app.create');
    Route::get('/github/app/created', [GitHubAppController::class, 'created'])->name('github.app.created');
    Route::post('/github/app/retry', [GitHubAppController::class, 'retry'])->middleware('throttle:10,1')->name('github.app.retry');
    Route::get('/github/connect', [GitHubAppController::class, 'connect'])->middleware('throttle:20,1')->name('github.connect');
    Route::get('/github/callback', [GitHubAppController::class, 'callback'])->middleware('throttle:20,1')->name('github.callback');
    Route::post('/locale/{locale}', function (string $locale) {
        abort_unless(isset(SetLocale::LOCALES[$locale]), 404);
        auth()->user()->update(['locale' => $locale]);

        return back();
    })->name('locale.set');
    Route::get('/sites/{site}/xhgui', fn (Site $site) => $site->xhprof === null ? abort(404) : redirect()->away(XhguiAuthController::link($site)))
        ->middleware('throttle:30,1')->name('sites.xhgui');
    Route::get('/mailboxes/{mailbox}/webmail', OpenWebmailController::class)->middleware('throttle:30,1')->name('mailboxes.webmail');
    Route::get('/mailboxes/{mailbox}/apple.mobileconfig', AppleMailProfileController::class)->name('mailboxes.apple-profile');
    Route::get('/ssl/root-ca.crt', function () {
        return response(app(ManageTls::class)->rootCa()['pem'], 200, [
            'Content-Type' => 'application/x-x509-ca-cert',
            'Content-Disposition' => 'attachment; filename="wspanel-root-ca.crt"',
        ]);
    })->name('ssl.root-ca');

    // An administrator viewing the panel as a client, and the way back.
    Route::post('/impersonation/leave', function (Impersonation $impersonation) {
        return $impersonation->leave() ? redirect('/clients') : redirect('/');
    })->name('impersonation.leave');

    // The panel: every registered resource, page and dashboard, at the root.
    Route::wire('panel');
});

// One-time code flows of wire-module-auth (each routed only while switched on).
Route::wire('auth-codes');
