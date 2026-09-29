<?php

use App\Http\Controllers\AccountingControlController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\Api\BookingRulesController;
use App\Http\Controllers\BalanceController;
use App\Http\Controllers\CarrierShipmentController;
use App\Http\Controllers\CustomerConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EconomicAuditController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FinancialMovementController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderLabelController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PendingAccountController;
use App\Http\Controllers\PendingSettlementController;
use App\Http\Controllers\PickupGroupController;
use App\Http\Controllers\PickupScheduleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RealtimeController;
use App\Http\Controllers\RecipientIncidentController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RiderAssignmentController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShippingPriceController;
use App\Http\Controllers\ShippingRateController;
use App\Http\Controllers\StoreReportController;
use App\Http\Controllers\TrackingController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\EnsureStaff;
use App\UserRole;
use Illuminate\Support\Facades\Route;

Route::get('/conversation/{token}', [CustomerConversationController::class, 'show'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:conversation')->name('conversation.show');
Route::post('/conversation/{token}', [CustomerConversationController::class, 'store'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:conversation-write')->name('conversation.store');

Route::get('/track/{token}/realtime/configuration', [TrackingController::class, 'realtime'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:tracking');
Route::post('/track/{token}/realtime/auth', [TrackingController::class, 'realtime'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:tracking');

Route::get('/track/{token}', [TrackingController::class, 'show'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:tracking')->name('tracking.public');

Route::get('/', function () {
    return view('welcome');
})->middleware('throttle:public-pages');

Route::middleware(['auth', EnsureStaff::class, EnsureEmailVerified::class])->group(function () {
    Route::get('/realtime/configuration', [RealtimeController::class, 'configuration'])->middleware('throttle:60,1');
    Route::post('/realtime/auth', [RealtimeController::class, 'authenticate'])->middleware('throttle:realtime-writes');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/orders', fn () => redirect()->route(auth()->user()->role === UserRole::Rider ? 'orders.in-progress' : 'orders.incoming'))->name('orders.index');
    Route::get('/orders/incoming', [OrderController::class, 'index'])->name('orders.incoming');
    Route::get('/orders/in-progress', [OrderController::class, 'index'])->name('orders.in-progress');
    Route::get('/orders/history', [OrderController::class, 'index'])->name('orders.history');
    Route::get('/tracking', [TrackingController::class, 'index'])->name('tracking.index');
    Route::get('/pickups', [PickupGroupController::class, 'index'])->name('pickups.index');
    Route::get('/labels', [OrderLabelController::class, 'index'])->name('labels.index');
    Route::get('/stores', [StoreReportController::class, 'index'])->middleware(EnsureAdmin::class)->name('stores.index');
    Route::get('/orders/create', [OrderController::class, 'create'])->middleware(EnsureAdmin::class)->name('orders.create');
    Route::post('/orders/checkout/edit', [OrderController::class, 'editCheckout'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('orders.checkout.edit');
    Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('orders.store');
    Route::get('/rates/{rate}/history', [ShippingRateController::class, 'history'])->middleware(EnsureAdmin::class)->name('rates.history');
    Route::patch('/rates/{rate}/state', [ShippingRateController::class, 'state'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('rates.state');
    Route::delete('/rates/{rate}', [ShippingRateController::class, 'destroy'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('rates.destroy');
    Route::get('/booking-rules', BookingRulesController::class)->name('booking-rules');
    Route::get('/rates', [ShippingRateController::class, 'index'])->middleware(EnsureAdmin::class)->name('rates.index');
    Route::get('/rates/quote', [ShippingRateController::class, 'quote'])->middleware(EnsureAdmin::class)->name('rates.quote');
    Route::post('/rates/{rate?}', [ShippingRateController::class, 'store'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('rates.store');
    Route::patch('/orders/{order}/carrier', [CarrierShipmentController::class, 'update'])->middleware('throttle:writes')->name('orders.carrier');
    Route::patch('/orders/{order}/price', [ShippingPriceController::class, 'update'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('prices.update');
    Route::patch('/orders/{order}/rider', [RiderAssignmentController::class, 'update'])->middleware([EnsureAdmin::class, 'throttle:writes'])->name('orders.rider');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::patch('/orders/{order}', [OrderController::class, 'update'])->middleware('throttle:writes')->name('orders.update');
    Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('/messages/{order}', [MessageController::class, 'show'])->name('messages.show');
    Route::post('/messages/{order}', [MessageController::class, 'store'])->middleware('throttle:writes')->name('messages.store');
    Route::patch('/messages/{order}/read', [MessageController::class, 'read'])->middleware('throttle:realtime-writes')->name('messages.read');
    Route::post('/messages/{order}/share', [MessageController::class, 'share'])->middleware('throttle:writes')->name('messages.share');
    Route::get('/notifications/feed', [NotificationController::class, 'feed'])->middleware('throttle:60,1')->name('notifications.feed');
    Route::get('/notifications/orders/{orderId}', [NotificationController::class, 'history'])->whereNumber('orderId')->middleware('throttle:60,1')->name('notifications.history');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/read-all', [NotificationController::class, 'readAll'])->middleware('throttle:writes')->name('notifications.read-all');
    Route::patch('/notifications/{notification}', [NotificationController::class, 'update'])->middleware('throttle:writes')->name('notifications.update');
    Route::get('/recipient-incidents', [RecipientIncidentController::class, 'index'])->middleware(EnsureAdmin::class)->name('recipient-incidents.index');
    Route::patch('/recipient-incidents/{incident}', [RecipientIncidentController::class, 'update'])->middleware([EnsureAdmin::class, 'throttle:writes'])->name('recipient-incidents.update');
    Route::get('/pending', [PendingAccountController::class, 'index'])->middleware(EnsureAdmin::class)->name('pending.index');
    Route::post('/pending', [PendingAccountController::class, 'store'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('pending.store');
    Route::patch('/pending/{account}', [PendingAccountController::class, 'update'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('pending.update');
    Route::post('/pending/{account}/settlements', [PendingAccountController::class, 'settle'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('pending.settle');
    Route::patch('/pending/{account}/settlements/{settlement}', [PendingSettlementController::class, 'update'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('pending.settlements.update');
    Route::patch('/settings/accounting', [AccountingControlController::class, 'update'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('settings.accounting');
    Route::patch('/orders/{order}/pickup-schedule', [PickupScheduleController::class, 'update'])->middleware('throttle:writes')->name('orders.pickup-schedule');
    Route::get('/economic-audits', [EconomicAuditController::class, 'index'])->middleware(EnsureAdmin::class)->name('audits.index');
    Route::get('/balance', [BalanceController::class, 'index'])->middleware(EnsureAdmin::class)->name('balance.index');
    Route::get('/reports', [ReportController::class, 'index'])->middleware(EnsureAdmin::class)->name('reports.index');
    Route::post('/balance/{order}/payment', [PaymentController::class, 'store'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('payments.store');
    Route::patch('/expenses/{expense}', [ExpenseController::class, 'update'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('expenses.update');
    Route::get('/movements', [FinancialMovementController::class, 'index'])->middleware(EnsureAdmin::class)->name('movements.index');
    Route::get('/movements/{movement}', [FinancialMovementController::class, 'show'])->middleware(EnsureAdmin::class)->name('movements.show');
    Route::post('/movements', [FinancialMovementController::class, 'store'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('movements.store');
    Route::patch('/movements/{movement}', [FinancialMovementController::class, 'update'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('movements.update');
    Route::delete('/movements/{movement}', [FinancialMovementController::class, 'destroy'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('movements.destroy');
    Route::post('/expenses', [ExpenseController::class, 'store'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('expenses.store');
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('expenses.destroy');
    Route::patch('/settings', [SettingsController::class, 'update'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('settings.update');
    Route::get('/settings/users', [AdminUserController::class, 'index'])->middleware(EnsureAdmin::class)->name('users.index');
    Route::get('/settings/users/create', [AdminUserController::class, 'create'])->middleware(EnsureAdmin::class)->name('users.create');
    Route::post('/settings/users', [AdminUserController::class, 'store'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('users.store');
    Route::patch('/settings/users/{user}', [AdminUserController::class, 'update'])->middleware('throttle:writes')->middleware(EnsureAdmin::class)->name('users.update');
    Route::get('/settings', [SettingsController::class, 'edit'])->middleware(EnsureAdmin::class)->name('settings.index');

});

Route::middleware(['auth', EnsureStaff::class, EnsureEmailVerified::class, 'throttle:writes'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

require __DIR__.'/customer.php';
