<?php

namespace App\Providers\Filament;

use App\Actions\Sites\ManageTls;
use App\Http\Controllers\DownloadBackupController;
use App\Http\Controllers\OpenDatabaseToolController;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName('WSPanel')
            ->authenticatedRoutes(function () {
                Route::get('/backups/{backup}/download', DownloadBackupController::class)->name('backups.download');
                Route::get('/ssl/root-ca.crt', function () {
                    $pem = app(ManageTls::class)->rootCa()['pem'];

                    return response($pem, 200, ['Content-Type' => 'application/x-x509-ca-cert', 'Content-Disposition' => 'attachment; filename="wspanel-root-ca.crt"']);
                })->name('ssl.root-ca');
                Route::get('/db-open/{database}/{tool}', OpenDatabaseToolController::class)
                    ->whereIn('tool', ['phpmyadmin', 'adminer'])
                    ->middleware('throttle:30,1')
                    ->name('dbtools.open');
            })
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
