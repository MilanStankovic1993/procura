<?php

use App\Http\Controllers\SpaPageController;
use Illuminate\Support\Facades\Route;

Route::controller(SpaPageController::class)->group(function (): void {
    Route::get('/', 'home')->name('home');
    Route::get('/login', 'login')->middleware('guest:web')->name('login');
    Route::get('/register', 'register')->middleware('guest:web')->name('register');
    Route::get('/forgot-password', 'forgotPassword')->middleware('guest:web')->name('password.request');
    Route::get('/reset-password/{token}', 'resetPassword')->middleware('guest:web')->name('password.reset');
    Route::get('/email/verify', 'verificationNotice')->middleware('auth:web')->name('verification.notice');
    Route::get('/user/confirm-password', 'confirmPassword')->middleware('auth:web')->name('password.confirm');

    Route::get('/dashboard', 'dashboard')
        ->middleware(['auth', 'verified'])
        ->name('dashboard');

    Route::get('/reset-password', 'application');
    Route::get('/verify-email', 'application');
    Route::get('/confirm-password', 'application');
    Route::get('/workspace-unavailable', 'application');
    Route::get('/invitations/accept', 'application');
    Route::get('/app/{path?}', 'application')
        ->where('path', '.*')
        ->name('spa.application');
});
