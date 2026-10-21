<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommitteeMembershipChange extends Model
{
    protected $fillable = ['club_id', 'club_membership_id', 'proposed_by', 'operation', 'status', 'reason', 'reviewed_by', 'review_reason', 'reviewed_at'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(ClubMembership::class, 'club_membership_id');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
