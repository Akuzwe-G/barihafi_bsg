<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Club extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'is_active', 'join_code', 'code', 'description', 'contact_phone', 'status'];

    protected static function booted(): void
    {
        static::creating(function (Club $club): void {
            $club->code ??= 'BHF-'.Str::upper(Str::random(10));
            $club->status ??= $club->is_active === false ? 'inactive' : 'active';
        });
        static::saving(function (Club $club): void {
            if ($club->isDirty('status')) {
                $club->is_active = $club->status === 'active';
            } elseif ($club->isDirty('is_active')) {
                $club->status = $club->is_active ? 'active' : 'inactive';
            }
        });
    }

    public function activities(): HasMany
    {
        return $this->hasMany(PlatformActivity::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'suspended_at' => 'datetime'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ClubMembership::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(ClubInvitation::class);
    }

    public function joinRequests(): HasMany
    {
        return $this->hasMany(ClubJoinRequest::class);
    }
}
