<?php

use App\Http\Controllers\AccountSetupController;
use App\Http\Controllers\AdminClubController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClubController;
use App\Http\Controllers\JoiningController;
use App\Http\Controllers\PasswordChangeController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\RecoveryController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));
Route::middleware('guest')->group(function () {
    Route::view('/login', 'login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::view('/forgot-password', 'recovery')->name('password.request');
});
Route::get('/join', [JoiningController::class, 'show'])->name('join');
Route::post('/join', [JoiningController::class, 'store'])->middleware('throttle:10,1')->name('join.store');
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::view('/change-password', 'password-change')->name('password.change');
    Route::post('/change-password', [PasswordChangeController::class, 'update'])->middleware('throttle:5,1')->name('password.change.update');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [ClubController::class, 'dashboard'])->name('dashboard');
    Route::middleware('can:platform')->group(function () {
        Route::get('/platform', [PlatformController::class, 'dashboard'])->name('platform');
        Route::post('/platform/clubs', [ClubController::class, 'store'])->name('platform.clubs.store');
        Route::patch('/platform/clubs/{club}', [ClubController::class, 'update'])->name('platform.clubs.update');
        Route::post('/platform/clubs/{club}/administrator', [ClubController::class, 'assign'])->name('platform.clubs.assign');
    });
    Route::get('/clubs/{club}', [ClubController::class, 'show'])->name('clubs.show');
    Route::get('/clubs/{club}/members', [ClubController::class, 'members'])->name('clubs.members');
    Route::patch('/clubs/{club}/members/{membership}', [ClubController::class, 'member'])->name('clubs.members.update');
    Route::post('/clubs/{club}/invitations', [ClubController::class, 'invite'])->middleware('throttle:10,1')->name('clubs.invite');
});

Route::get('/reset-password/{token}', fn (string $token) => view('reset', ['token' => $token, 'phone' => request('phone', '')]))->name('password.reset');
Route::post('/reset-password', [RecoveryController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');

Route::middleware(['auth', 'auth.session', 'can:platform'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/dashboard', [PlatformController::class, 'dashboard'])->name('dashboard');
    Route::get('/clubs', [AdminClubController::class, 'index'])->name('clubs.index');
    Route::get('/clubs/create', [AdminClubController::class, 'create'])->name('clubs.create');
    Route::post('/clubs', [AdminClubController::class, 'store'])->name('clubs.store');
    Route::get('/clubs/{club}', [AdminClubController::class, 'show'])->name('clubs.show');
    Route::get('/clubs/{club}/edit', [AdminClubController::class, 'edit'])->name('clubs.edit');
    Route::patch('/clubs/{club}', [AdminClubController::class, 'update'])->name('clubs.update');
    Route::patch('/clubs/{club}/status', [AdminClubController::class, 'status'])->name('clubs.status');
    Route::post('/clubs/{club}/administrator', [AdminClubController::class, 'assign'])->name('clubs.assign');
    Route::get('/users', [PlatformController::class, 'users'])->name('users.index');
    Route::get('/users/{user}', [PlatformController::class, 'user'])->name('users.show');
    Route::patch('/users/{user}/status', [PlatformController::class, 'userStatus'])->name('users.status');
    Route::post('/users/{user}/setup-invitation', [PlatformController::class, 'reissueSetup'])->middleware('throttle:10,1')->name('users.setup');
    Route::get('/activities', [PlatformController::class, 'activities'])->name('activities');
    Route::get('/settings', [PlatformController::class, 'settings'])->name('settings');
    Route::patch('/settings', [PlatformController::class, 'updateSettings'])->name('settings.update');
    Route::get('/profile', [PlatformController::class, 'profile'])->name('profile');
    Route::patch('/profile', [PlatformController::class, 'updateProfile'])->name('profile.update');
});
Route::middleware('guest')->group(function (): void {
    Route::get('/account/setup/{token}', [AccountSetupController::class, 'show'])->middleware('throttle:20,1')->name('account.setup');
    Route::post('/account/setup/{token}', [AccountSetupController::class, 'store'])->middleware('throttle:5,1')->name('account.setup.store');
});
Route::get('/clubs/{club}/onboarding', [ClubController::class, 'onboarding'])->middleware(['auth', 'auth.session'])->name('clubs.onboarding');
