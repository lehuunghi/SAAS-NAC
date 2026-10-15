<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommerceController;
use App\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app')->name('login');
Route::view('/san-pham', 'app');
Route::prefix('saas/api/v1')->group(function () {
    Route::get('catalog', [CommerceController::class, 'catalog']);
    Route::post('register', [CommerceController::class, 'register'])->middleware('throttle:3,60');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::middleware('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', fn () => ['name' => auth()->user()->name, 'email' => auth()->user()->email]);
        Route::get('tenants', [TenantController::class, 'index']);
        Route::get('tenants/{tenant}', [TenantController::class, 'show']);
        Route::post('tenants/{tenant}/sites', [TenantController::class, 'storeSite'])->middleware('throttle:30,1');
        Route::post('tenants/{tenant}/environments', [TenantController::class, 'provision'])->middleware('throttle:5,1');
        Route::get('tenants/{tenant}/operations/{operation}', [TenantController::class, 'operation']);
        Route::get('tenants/{tenant}/billing', [CommerceController::class, 'billing']);
        Route::post('tenants/{tenant}/quotes', [CommerceController::class, 'quote'])->middleware('throttle:10,60');
        Route::get('tenants/{tenant}/orders/{order}', [CommerceController::class, 'order']);
        Route::post('tenants/{tenant}/orders/{order}', [CommerceController::class, 'updateOrder'])->middleware('throttle:20,1');
        Route::get('tenants/{tenant}/tickets', [CommerceController::class, 'tickets']);
        Route::post('tenants/{tenant}/tickets', [CommerceController::class, 'storeTicket'])->middleware('throttle:10,60');
    });
});
