<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailOtpController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordRecoveryController;
use App\Http\Controllers\Auth\RiderActivationController;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\ProtectAuthentication;
use Illuminate\Support\Facades\Route;

Route::middleware(['guest', 'throttle:public-pages'])->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware([ProtectAuthentication::class]);

    Route::get('forgot-password', [PasswordRecoveryController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordRecoveryController::class, 'store'])->name('password.email');
    Route::get('reset-password/{token}', [PasswordRecoveryController::class, 'edit'])->where('token', '[A-Za-z0-9]{1,128}')->name('password.reset');
    Route::post('reset-password', [PasswordRecoveryController::class, 'update'])->name('password.store');
    Route::get('activate/{token}', [RiderActivationController::class, 'edit'])->where('token', '[A-Za-z0-9]{1,128}')->name('rider.activation');
    Route::post('activate', [RiderActivationController::class, 'update'])->name('rider.activate');

});

Route::middleware(['auth', 'auth.session', 'throttle:writes'])->group(function () {
    Route::get('verify-email-otp', [EmailOtpController::class, 'show'])->middleware(EnsureStaff::class)->name('email-otp.notice');
    Route::post('verify-email-otp/send', [EmailOtpController::class, 'store'])->middleware([EnsureStaff::class])->block(10, 10)->name('email-otp.send');
    Route::post('verify-email-otp/confirm', [EmailOtpController::class, 'update'])->middleware([EnsureStaff::class])->block(10, 10)->name('email-otp.verify');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->middleware(EnsureStaff::class)
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])->middleware([EnsureStaff::class, ProtectAuthentication::class.':confirm']);

    Route::put('password', [PasswordController::class, 'update'])->middleware([EnsureStaff::class, ProtectAuthentication::class.':password'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->block(10, 10)
        ->name('logout');
});
