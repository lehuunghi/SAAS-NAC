<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app')->name('login');
Route::prefix('saas/api/v1')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::middleware('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', fn () => ['name' => auth()->user()->name, 'email' => auth()->user()->email]);
        Route::get('tenants', [TenantController::class, 'index']);
        Route::get('tenants/{tenant}', [TenantController::class, 'show']);
        Route::post('tenants/{tenant}/sites', [TenantController::class, 'storeSite'])->middleware('throttle:30,1');
        Route::post('tenants/{tenant}/environments', [TenantController::class, 'provision'])->middleware('throttle:5,1');
        Route::get('tenants/{tenant}/operations/{operation}', [TenantController::class, 'operation']);
    });
});
