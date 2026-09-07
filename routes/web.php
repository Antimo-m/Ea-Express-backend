<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\EnsureStaff;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'verified', EnsureStaff::class])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::redirect('/orders', '/orders/incoming')->name('orders.index');
    Route::view('/orders/incoming', 'orders.incoming')->name('orders.incoming');
    Route::view('/orders/in-progress', 'orders.in-progress')->name('orders.in-progress');
    Route::view('/orders/history', 'orders.history')->name('orders.history');
    Route::view('/tracking', 'tracking.index')->name('tracking.index');
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
