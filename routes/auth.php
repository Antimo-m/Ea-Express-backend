<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailOtpController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\ProtectAuthentication;
use Illuminate\Support\Facades\Route;

Route::middleware(['guest', 'throttle:public-pages'])->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware([ProtectAuthentication::class]);

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
