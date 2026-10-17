<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubMembership extends Model
{
    public const ROLES = ['Club Administrator', 'President', 'Accountant', 'Committee Member', 'Member'];

    protected $fillable = ['club_id', 'user_id', 'status', 'roles', 'joined_at', 'activated_at', 'has_onboarding_access'];

    protected function casts(): array
    {
        return ['has_onboarding_access' => 'boolean', 'roles' => 'array', 'joined_at' => 'datetime', 'activated_at' => 'datetime'];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
