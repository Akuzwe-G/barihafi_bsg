<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubMembership extends Model
{
    public const ROLES = ['Club Administrator', 'President', 'Accountant', 'Committee Member', 'Member'];

    protected $attributes = ['admission_status' => 'approved', 'has_governance_access' => false, 'has_onboarding_access' => false, 'is_committee_approver' => false];

    protected $fillable = ['club_id', 'user_id', 'status', 'roles', 'joined_at', 'activated_at', 'has_onboarding_access', 'admission_status', 'has_governance_access', 'is_committee_approver'];

    protected function casts(): array
    {
        return ['has_onboarding_access' => 'boolean', 'has_governance_access' => 'boolean', 'is_committee_approver' => 'boolean', 'roles' => 'array', 'joined_at' => 'datetime', 'activated_at' => 'datetime'];
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
