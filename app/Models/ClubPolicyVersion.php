<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubPolicyVersion extends Model
{
    protected $fillable = ['club_id', 'version', 'financial_rule_proposal_id', 'rules', 'approved_by', 'approved_at', 'effective_from', 'effective_to', 'active_club_id', 'is_baseline'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'effective_from' => 'datetime', 'effective_to' => 'datetime', 'is_baseline' => 'boolean', 'approved_at' => 'datetime', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Approved policy versions are immutable. Submit a new proposal.'));
        static::deleting(fn () => throw new \LogicException('Approved policy versions cannot be deleted.'));
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
