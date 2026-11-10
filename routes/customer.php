<?php

use App\Http\Controllers\Api\BookingRulesController;
use App\Http\Controllers\Api\CustomerAuthController as Auth;
use App\Http\Controllers\Api\CustomerLabelController;
use App\Http\Controllers\Api\CustomerMessageController as Messages;
use App\Http\Controllers\Api\CustomerOrderController as Orders;
use App\Http\Controllers\Api\CustomerPendingController;
use App\Http\Controllers\Api\CustomerPreferenceController;
use App\Http\Controllers\Api\CustomerPrivacyController;
use App\Http\Controllers\Api\CustomerStatisticsController;
use App\Http\Controllers\Api\CustomerTextPolicyController;
use App\Http\Controllers\Api\CustomerWorkspaceController as Workspace;
use App\Http\Controllers\Api\SenderAddressController;
use App\Http\Controllers\RealtimeController;
use App\Http\Controllers\ShippingPriceController;
use App\Http\Controllers\ShippingRateController;
use App\Http\Middleware\EnsureCustomer;
use App\Http\Middleware\ProtectAuthentication;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/customer')->name('customer.')->middleware('throttle:customer-api')->group(function (): void {
    Route::get('csrf', [Auth::class, 'csrf']);
    Route::get('privacy', CustomerPrivacyController::class);
    Route::get('text-policy', CustomerTextPolicyController::class);
    Route::post('auth/login', [Auth::class, 'login'])->middleware([ProtectAuthentication::class]);
    Route::post('auth/register', [Auth::class, 'register'])->middleware(ProtectAuthentication::class.':register');
    Route::middleware(['auth:customer', 'auth.session', EnsureCustomer::class])->group(function (): void {
        Route::get('realtime/configuration', [RealtimeController::class, 'configuration'])->middleware('throttle:60,1');
        Route::post('realtime/auth', [RealtimeController::class, 'authenticate'])->middleware('throttle:realtime-writes');
        Route::get('auth/me', [Auth::class, 'me']);
        Route::patch('display-preferences', [CustomerPreferenceController::class, 'update'])->middleware('throttle:writes');
        Route::post('auth/logout', [Auth::class, 'logout']);
        Route::get('sender-address', [SenderAddressController::class, 'show']);
        Route::put('sender-address', [SenderAddressController::class, 'update'])->middleware('throttle:writes');
        Route::delete('sender-address', [SenderAddressController::class, 'destroy'])->middleware('throttle:writes');
        Route::get('booking-rules', BookingRulesController::class);
        Route::get('pending', [CustomerPendingController::class, 'index']);
        Route::get('pending/{account}', [CustomerPendingController::class, 'show']);
        Route::get('statistics', CustomerStatisticsController::class);
        Route::get('dashboard', [Workspace::class, 'dashboard']);
        Route::get('rates', [ShippingRateController::class, 'index']);
        Route::get('rates/quote', [ShippingRateController::class, 'quote']);
        Route::patch('orders/{order}/price', [ShippingPriceController::class, 'update'])->middleware('throttle:writes');
        Route::get('orders', [Orders::class, 'index']);
        Route::get('orders/labels', [CustomerLabelController::class, 'index']);
        Route::get('orders/{order}', [Orders::class, 'show']);
        Route::get('orders/{order}/messages', [Messages::class, 'index']);
        Route::get('notifications/feed', [Workspace::class, 'feed']);
        Route::get('notifications/orders/{order}', [Workspace::class, 'history']);
        Route::get('notifications', [Workspace::class, 'notifications']);
        Route::patch('orders/{order}/messages/read', [Messages::class, 'read'])->middleware('throttle:realtime-writes');
        Route::middleware('throttle:writes')->group(function (): void {
            Route::post('orders/checkout', [Orders::class, 'review'])->name('checkout');
            Route::post('orders/{order}/checkout', [Orders::class, 'review'])->name('orders.review');
            Route::post('orders', [Orders::class, 'store']);
            Route::patch('orders/{order}', [Orders::class, 'update']);
            Route::post('orders/{order}/cancel', [Orders::class, 'cancel']);
            Route::post('orders/{order}/messages', [Messages::class, 'store']);
            Route::patch('notifications/read-all', [Workspace::class, 'readAll']);
            Route::patch('notifications/{id}/read', [Workspace::class, 'readNotification']);
            Route::patch('profile', [Workspace::class, 'profile'])->middleware('throttle:profile-update');
            Route::put('password', [Workspace::class, 'password'])->middleware(ProtectAuthentication::class.':password');
            Route::patch('preferences', [Workspace::class, 'preferences']);
        });
    });
});
