<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubPolicyVersion extends Model
{
    protected $fillable = ['club_id', 'version', 'financial_rule_proposal_id', 'rules', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'approved_at' => 'datetime', 'version' => 'integer'];
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
