<?php

use App\Actions\Sites\ManageTls;
use App\Http\Controllers\DownloadBackupController;
use App\Http\Controllers\ForwardAuthController;
use App\Http\Controllers\OpenDatabaseServerToolController;
use App\Http\Controllers\OpenDatabaseToolController;
use Illuminate\Support\Facades\Route;

// Edge forward_auth for Mailpit (outside the panel group: answers 204 or redirects).
Route::get('/auth/check', ForwardAuthController::class)->name('auth.check');

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/backups/{backup}/download', DownloadBackupController::class)->name('backups.download');
    Route::get('/db-open/{database}/{tool}', OpenDatabaseToolController::class)
        ->whereIn('tool', ['phpmyadmin', 'adminer'])
        ->middleware('throttle:30,1')
        ->name('dbtools.open');
    Route::get('/db-open-server/{engine}/{tool}', OpenDatabaseServerToolController::class)
        ->whereIn('engine', ['mariadb', 'postgresql'])
        ->whereIn('tool', ['phpmyadmin', 'adminer'])
        ->middleware('throttle:30,1')
        ->name('dbtools.server');
    Route::get('/ssl/root-ca.crt', function () {
        return response(app(ManageTls::class)->rootCa()['pem'], 200, [
            'Content-Type' => 'application/x-x509-ca-cert',
            'Content-Disposition' => 'attachment; filename="wspanel-root-ca.crt"',
        ]);
    })->name('ssl.root-ca');

    // The panel: every registered resource, page and dashboard, at the root.
    Route::wire('panel');
});

// One-time code flows of wire-module-auth (each routed only while switched on).
Route::wire('auth-codes');
