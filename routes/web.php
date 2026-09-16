<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\BalanceController;
use App\Http\Controllers\CustomerConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RealtimeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TrackingController;
use App\Http\Middleware\EnsurePhoneVerified;
use App\Http\Middleware\EnsureStaff;
use Illuminate\Support\Facades\Route;

Route::get('/conversation/{token}', [CustomerConversationController::class, 'show'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:conversation')->name('conversation.show');
Route::post('/conversation/{token}', [CustomerConversationController::class, 'store'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:conversation-write')->name('conversation.store');

Route::get('/track/{token}/realtime/configuration', [TrackingController::class, 'realtime'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:tracking');
Route::post('/track/{token}/realtime/auth', [TrackingController::class, 'realtime'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:tracking');

Route::get('/track/{token}', [TrackingController::class, 'show'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:tracking')->name('tracking.public');

Route::get('/', function () {
    return view('welcome');
})->middleware('throttle:public-pages');

Route::middleware(['auth', EnsureStaff::class, EnsurePhoneVerified::class])->group(function () {
    Route::get('/realtime/configuration', [RealtimeController::class, 'configuration'])->middleware('throttle:60,1');
    Route::post('/realtime/auth', [RealtimeController::class, 'authenticate'])->middleware('throttle:writes');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/orders', fn () => redirect()->route('orders.incoming'))->name('orders.index');
    Route::get('/orders/incoming', [OrderController::class, 'index'])->name('orders.incoming');
    Route::get('/orders/in-progress', [OrderController::class, 'index'])->name('orders.in-progress');
    Route::get('/orders/history', [OrderController::class, 'index'])->name('orders.history');
    Route::get('/tracking', [TrackingController::class, 'index'])->name('tracking.index');
    Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
    Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:writes')->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::patch('/orders/{order}', [OrderController::class, 'update'])->middleware('throttle:writes')->name('orders.update');
    Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('/messages/{order}', [MessageController::class, 'show'])->name('messages.show');
    Route::post('/messages/{order}', [MessageController::class, 'store'])->middleware('throttle:writes')->name('messages.store');
    Route::patch('/messages/{order}/read', [MessageController::class, 'read'])->middleware('throttle:writes')->name('messages.read');
    Route::post('/messages/{order}/share', [MessageController::class, 'share'])->middleware('throttle:writes')->name('messages.share');
    Route::get('/notifications/feed', [NotificationController::class, 'feed'])->middleware('throttle:60,1')->name('notifications.feed');
    Route::get('/notifications/orders/{orderId}', [NotificationController::class, 'history'])->whereNumber('orderId')->middleware('throttle:60,1')->name('notifications.history');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/read-all', [NotificationController::class, 'readAll'])->middleware('throttle:writes')->name('notifications.read-all');
    Route::patch('/notifications/{notification}', [NotificationController::class, 'update'])->middleware('throttle:writes')->name('notifications.update');
    Route::get('/balance', [BalanceController::class, 'index'])->name('balance.index');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::post('/balance/{order}/payment', [PaymentController::class, 'store'])->middleware('throttle:writes')->name('payments.store');
    Route::post('/expenses', [ExpenseController::class, 'store'])->middleware('throttle:writes')->name('expenses.store');
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->middleware('throttle:writes')->name('expenses.destroy');
    Route::patch('/settings', [SettingsController::class, 'update'])->middleware('throttle:writes')->name('settings.update');
    Route::get('/settings/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::get('/settings/users/create', [AdminUserController::class, 'create'])->name('users.create');
    Route::post('/settings/users', [AdminUserController::class, 'store'])->middleware('throttle:writes')->name('users.store');
    Route::patch('/settings/users/{user}', [AdminUserController::class, 'update'])->middleware('throttle:writes')->name('users.update');
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.index');

});

Route::middleware(['auth', EnsureStaff::class, EnsurePhoneVerified::class, 'throttle:writes'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

require __DIR__.'/customer.php';
