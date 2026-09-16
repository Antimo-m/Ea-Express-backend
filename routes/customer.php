<?php

use App\Http\Controllers\Api\CustomerAuthController as Auth;
use App\Http\Controllers\Api\CustomerMessageController as Messages;
use App\Http\Controllers\Api\CustomerOrderController as Orders;
use App\Http\Controllers\Api\CustomerWorkspaceController as Workspace;
use App\Http\Controllers\RealtimeController;
use App\Http\Middleware\EnsureCustomer;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/customer')->name('customer.')->middleware('throttle:customer-api')->group(function (): void {
    Route::get('csrf', [Auth::class, 'csrf']);
    Route::post('auth/login', [Auth::class, 'login'])->middleware('throttle:customer-login');
    Route::post('auth/register', [Auth::class, 'register'])->middleware('throttle:registration');
    Route::post('auth/forgot-password', [Auth::class, 'forgot'])->middleware('throttle:recovery');
    Route::post('auth/reset-password', [Auth::class, 'reset'])->middleware('throttle:reset-password');
    Route::middleware(['auth:customer', EnsureCustomer::class])->group(function (): void {
        Route::get('realtime/configuration', [RealtimeController::class, 'configuration'])->middleware('throttle:60,1');
        Route::post('realtime/auth', [RealtimeController::class, 'authenticate'])->middleware('throttle:writes');
        Route::get('auth/me', [Auth::class, 'me']);
        Route::post('auth/logout', [Auth::class, 'logout']);
        Route::get('dashboard', [Workspace::class, 'dashboard']);
        Route::get('couriers', [Workspace::class, 'couriers']);
        Route::get('orders', [Orders::class, 'index']);
        Route::get('orders/{order}', [Orders::class, 'show']);
        Route::get('orders/{order}/messages', [Messages::class, 'index']);
        Route::get('notifications/feed', [Workspace::class, 'feed']);
        Route::get('notifications/orders/{orderId}', [Workspace::class, 'history'])->whereNumber('orderId');
        Route::get('notifications', [Workspace::class, 'notifications']);
        Route::middleware('throttle:writes')->group(function (): void {
            Route::post('orders', [Orders::class, 'store']);
            Route::patch('orders/{order}', [Orders::class, 'update']);
            Route::post('orders/{order}/cancel', [Orders::class, 'cancel']);
            Route::post('orders/{order}/messages', [Messages::class, 'store']);
            Route::patch('orders/{order}/messages/read', [Messages::class, 'read']);
            Route::patch('notifications/read-all', [Workspace::class, 'readAll']);
            Route::patch('notifications/{id}/read', [Workspace::class, 'readNotification']);
            Route::patch('profile', [Workspace::class, 'profile']);
            Route::put('password', [Workspace::class, 'password']);
            Route::patch('preferences', [Workspace::class, 'preferences']);
        });
    });
});
