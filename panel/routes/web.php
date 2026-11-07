<?php

use App\Http\Controllers\ForwardAuthController;
use Illuminate\Support\Facades\Route;

// The whole app is the Filament panel (see AdminPanelProvider, path '').

Route::get('/auth/check', ForwardAuthController::class)->name('auth.check');
