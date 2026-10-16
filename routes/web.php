<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommerceController;
use App\Http\Controllers\TenantController;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsurePlatformAdmin;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app')->name('login');
Route::view('/san-pham', 'app');
Route::view('/admin', 'app');
Route::prefix('saas/api/v1')->group(function () {
    Route::get('catalog', [CommerceController::class, 'catalog']);
    Route::post('register', [CommerceController::class, 'register'])->middleware('throttle:registration');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::middleware(['auth', EnsureAccountActive::class, 'auth.session'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('password', [AuthController::class, 'password'])->middleware('throttle:account-security');
        Route::get('me', fn () => ['id' => auth()->id(), 'name' => auth()->user()->name, 'email' => auth()->user()->email, 'is_platform_admin' => auth()->user()->is_platform_admin]);
        Route::post('profile', [AccountController::class, 'profile']);
        Route::get('tenants/{tenant}/team', [AccountController::class, 'team']);
        Route::post('tenants/{tenant}/profile', [AccountController::class, 'company']);
        Route::post('tenants/{tenant}/members', [AccountController::class, 'member'])->middleware('throttle:tenant-team');
        Route::delete('tenants/{tenant}/members/{member}', [AccountController::class, 'removeMember'])->middleware('throttle:tenant-team');
        Route::get('tenants', [TenantController::class, 'index']);
        Route::get('tenants/{tenant}', [TenantController::class, 'show']);
        Route::post('tenants/{tenant}/sites', [TenantController::class, 'storeSite'])->middleware('throttle:tenant-sites');
        Route::post('tenants/{tenant}/environments', [TenantController::class, 'provision'])->middleware('throttle:tenant-install');
        Route::get('tenants/{tenant}/operations/{operation}', [TenantController::class, 'operation']);
        Route::get('tenants/{tenant}/billing', [CommerceController::class, 'billing']);
        Route::post('tenants/{tenant}/quotes', [CommerceController::class, 'quote'])->middleware('throttle:tenant-quotes');
        Route::get('tenants/{tenant}/orders/{order}', [CommerceController::class, 'order']);
        Route::post('tenants/{tenant}/orders/{order}', [CommerceController::class, 'updateOrder'])->middleware('throttle:tenant-orders');
        Route::get('tenants/{tenant}/tickets', [CommerceController::class, 'tickets']);
        Route::post('tenants/{tenant}/tickets', [CommerceController::class, 'storeTicket'])->middleware('throttle:tenant-support');
        Route::prefix('admin')->middleware([EnsurePlatformAdmin::class, 'throttle:platform-admin'])->group(function () {
            Route::get('overview', [AdminController::class, 'overview']);
            Route::get('customers', [AdminController::class, 'customers']);
            Route::post('customers', [AdminController::class, 'createCustomer']);
            Route::get('customers/{tenant}', [AdminController::class, 'customer']);
            Route::post('customers/{tenant}', [AdminController::class, 'updateCustomer']);
            Route::post('customers/{tenant}/install', [AdminController::class, 'install'])->middleware('throttle:platform-install');
            Route::get('sales', [AdminController::class, 'sales']);
            Route::post('quotes/{quote}/issue', [AdminController::class, 'issueQuote']);
            Route::post('orders/{order}/verify-payment', [AdminController::class, 'verifyPayment'])->middleware('throttle:platform-payment');
            Route::get('installations', [AdminController::class, 'installations']);
            Route::post('operations/{operation}/retry', [AdminController::class, 'retry']);
            Route::post('environments/{environment}/handover', [AdminController::class, 'handover']);
            Route::post('environments/{environment}/ssh-credentials', [AdminController::class, 'sshCredentials'])->middleware('throttle:account-security');
            Route::get('users', [AdminController::class, 'users']);
            Route::post('users/{user}', [AdminController::class, 'updateUser']);
            Route::get('tickets', [AdminController::class, 'tickets']);
            Route::post('tickets/{ticket}/reply', [AdminController::class, 'reply']);
            Route::get('settings', [AdminController::class, 'settings']);
            Route::get('audit', [AdminController::class, 'auditLog']);
        });
    });
});
