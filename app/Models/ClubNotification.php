<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubNotification extends Model
{
    protected $fillable = ['club_id', 'user_id', 'kind', 'message', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public static function send(Club $club, int $userId, string $kind, string $message): void
    {
        static::create(['club_id' => $club->id, 'user_id' => $userId, 'kind' => $kind, 'message' => $message]);
    }

    public static function administrators(Club $club, string $kind, string $message): void
    {
        foreach ($club->memberships()->whereIn('status', ['active', 'pending'])->whereJsonContains('roles', 'Club Administrator')->whereHas('user', fn ($q) => $q->where('account_status', 'active'))->get() as $membership) {
            if ($membership->status === 'active' || $membership->has_onboarding_access) {
                static::send($club, $membership->user_id, $kind, $message);
            }
        }
    }
}
