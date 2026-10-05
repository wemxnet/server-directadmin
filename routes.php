<?php

use App\Http\Middleware\RequireAdminReauthentication;
use Extensions\Servers\DirectAdmin\Http\Controllers\Admin\LoginController as AdminLoginController;
use Extensions\Servers\DirectAdmin\Http\Controllers\Client\LoginController as ClientLoginController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/orders/{order}/directadmin/login', ClientLoginController::class)
        ->name('directadmin.login');
});

Route::middleware(['web', 'auth', 'admin', RequireAdminReauthentication::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/orders/{order}/directadmin/login', AdminLoginController::class)
            ->middleware('permission:admin.orders.view')
            ->name('directadmin.login');
    });
