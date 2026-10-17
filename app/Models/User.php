<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Phone;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'phone', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    public const SYSTEM_ADMIN_ROLE = 'system_admin';

    protected $attributes = ['is_system_admin' => false, 'must_change_password' => false, 'account_status' => 'active'];

    public function hasPlatformRole(string $role): bool
    {
        return $role === self::SYSTEM_ADMIN_ROLE && $this->is_system_admin && $this->account_status === 'active';
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            $user->phone = Phone::normalize((string) $user->phone);
            $user->email = filled($user->email) ? strtolower(trim($user->email)) : null;
        });
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ClubMembership::class);
    }

    public function accessibleClubs(): Builder
    {
        return Club::query()->where('is_active', true)->whereHas('memberships', fn ($query) => $query->where('user_id', $this->id)->where(function ($membership): void {
            $membership->where('status', 'active')->orWhere(fn ($pending) => $pending->where('status', 'pending')->where('has_onboarding_access', true)->whereJsonContains('roles', 'Club Administrator'));
        }));
    }

    public function clubLandingRoute(Club $club): string
    {
        return $this->memberships()->where('club_id', $club->id)->where('status', 'active')->exists() ? 'clubs.show' : 'clubs.onboarding';
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system_admin' => 'boolean',
            'must_change_password' => 'boolean',
            'suspended_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
