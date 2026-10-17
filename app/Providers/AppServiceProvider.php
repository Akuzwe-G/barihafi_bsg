<?php

namespace App\Providers;

use App\Models\ClubNotification;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\View::composer('layouts.app', function (View $view): void {
            $view->with('branding', PlatformSetting::current());
            $view->with('unreadNoticeCount', auth()->check() && ! auth()->user()->must_change_password ? ClubNotification::where('user_id', auth()->id())->whereNull('read_at')->count() : 0);
        });
        Gate::define('platform', fn (User $user): bool => $user->hasPlatformRole(User::SYSTEM_ADMIN_ROLE));
    }
}
