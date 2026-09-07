<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TrackingController;
use App\Http\Middleware\EnsureStaff;
use Illuminate\Support\Facades\Route;

Route::get('/track/{token}', [TrackingController::class, 'show'])->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:tracking')->name('tracking.public');

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'verified', EnsureStaff::class])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::redirect('/orders', '/orders/incoming')->name('orders.index');
    Route::get('/orders/incoming', [OrderController::class, 'index'])->name('orders.incoming');
    Route::get('/orders/in-progress', [OrderController::class, 'index'])->name('orders.in-progress');
    Route::get('/orders/history', [OrderController::class, 'index'])->name('orders.history');
    Route::get('/tracking', [TrackingController::class, 'index'])->name('tracking.index');
    Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
    Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:writes')->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::patch('/orders/{order}', [OrderController::class, 'update'])->middleware('throttle:writes')->name('orders.update');
    Route::view('/messages', 'messages.index')->name('messages.index');
    Route::view('/balance', 'balance.index')->name('balance.index');
    Route::view('/reports', 'reports.index')->name('reports.index');
    Route::view('/settings', 'settings.index')->name('settings.index');

});

Route::middleware(['auth', EnsureStaff::class, 'throttle:writes'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
