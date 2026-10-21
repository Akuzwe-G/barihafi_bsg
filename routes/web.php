<?php

use App\Http\Controllers\AccountSetupController;
use App\Http\Controllers\AdminClubController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClubController;
use App\Http\Controllers\ClubGovernanceController;
use App\Http\Controllers\ClubJoinRequestController;
use App\Http\Controllers\ClubMemberController;
use App\Http\Controllers\ClubNotificationController;
use App\Http\Controllers\ClubSettingsController;
use App\Http\Controllers\ClubWorkspaceController;
use App\Http\Controllers\FinancialSettingsController;
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

Route::middleware(['auth', 'auth.session'])->group(function (): void {
    Route::get('/clubs/{club}/admin', [ClubWorkspaceController::class, 'dashboard'])->name('clubs.admin.dashboard');
    Route::get('/clubs/{club}/members/create', [ClubMemberController::class, 'create'])->name('clubs.members.create');
    Route::post('/clubs/{club}/members', [ClubMemberController::class, 'store'])->middleware('throttle:10,1')->name('clubs.members.store');
    Route::get('/clubs/{club}/members/{membership}', [ClubMemberController::class, 'show'])->name('clubs.members.show');
    Route::get('/clubs/{club}/requests', [ClubJoinRequestController::class, 'index'])->name('clubs.requests');
    Route::post('/clubs/{club}/requests/{joinRequest}/review', [ClubJoinRequestController::class, 'review'])->name('clubs.requests.review');
    Route::get('/my-requests', [ClubJoinRequestController::class, 'mine'])->name('requests.mine');
    Route::post('/club-invitations/{invitation}/accept', [JoiningController::class, 'accept'])->name('invitations.accept');
    Route::get('/clubs/{club}/settings', [ClubSettingsController::class, 'edit'])->name('clubs.settings');
    Route::patch('/clubs/{club}/settings', [ClubSettingsController::class, 'update'])->name('clubs.settings.update');
    Route::post('/clubs/{club}/settings/join-code', [ClubSettingsController::class, 'rotateCode'])->name('clubs.settings.code');
    Route::post('/clubs/{club}/payment-destinations', [ClubSettingsController::class, 'payment'])->name('clubs.payments.store');
    Route::patch('/clubs/{club}/payment-destinations/{destination}', [ClubSettingsController::class, 'payment'])->name('clubs.payments.update');
    Route::get('/clubs/{club}/payments', [ClubSettingsController::class, 'instructions'])->name('clubs.payments');
    Route::get('/clubs/{club}/governance', [ClubGovernanceController::class, 'index'])->name('clubs.governance');
    Route::post('/clubs/{club}/governance/proposals', [ClubGovernanceController::class, 'propose'])->name('clubs.governance.propose');
    Route::post('/clubs/{club}/governance/proposals/{proposal}/review', [ClubGovernanceController::class, 'review'])->name('clubs.governance.review');
    Route::get('/notifications', [ClubNotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/{notification}/read', [ClubNotificationController::class, 'read'])->name('notifications.read');
});
Route::middleware(['auth', 'auth.session', 'can:platform'])->prefix('admin/clubs/{club}')->group(function (): void {
    Route::get('/governance', [ClubGovernanceController::class, 'platform'])->name('admin.clubs.governance');
    Route::patch('/governance', [ClubGovernanceController::class, 'platformUpdate'])->name('admin.clubs.governance.update');
    Route::patch('/memberships/{membership}/roles', [ClubGovernanceController::class, 'platformRoles'])->name('admin.clubs.memberships.roles');
});

Route::get('/clubs/{club}/activities', [ClubWorkspaceController::class, 'activities'])->middleware(['auth', 'auth.session'])->name('clubs.activities');

Route::middleware(['auth', 'auth.session'])->prefix('clubs/{club}/financial-settings')->name('clubs.financial.')->group(function (): void {
    Route::get('/', [FinancialSettingsController::class, 'index'])->name('index');
    Route::post('/proposals', [FinancialSettingsController::class, 'store'])->name('proposals.store');
    Route::get('/proposals/{proposal}', [FinancialSettingsController::class, 'show'])->name('proposals.show');
    Route::post('/proposals/{proposal}/submit', [FinancialSettingsController::class, 'submit'])->name('proposals.submit');
    Route::post('/proposals/{proposal}/cancel', [FinancialSettingsController::class, 'cancel'])->name('proposals.cancel');
    Route::post('/proposals/{proposal}/review', [FinancialSettingsController::class, 'review'])->name('proposals.review');
    Route::post('/committee', [FinancialSettingsController::class, 'nominate'])->name('committee.nominate');
    Route::post('/committee/{change}/review', [FinancialSettingsController::class, 'reviewCommittee'])->name('committee.review');
    Route::get('/{page}', [FinancialSettingsController::class, 'index'])->name('page');
});
