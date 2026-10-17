<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialRuleProposal extends Model
{
    use HasFactory;

    public const RULE_LABELS = ['share_price' => 'Share price', 'minimum_membership_shares' => 'Minimum membership shares', 'loan_multiplier' => 'Loan multiplier', 'interest_rate' => 'Interest rate (%)', 'interest_method' => 'Interest method', 'loan_duration_months' => 'Loan duration (months)', 'repayment_frequency' => 'Repayment frequency', 'savings_withdrawal_notice_days' => 'Savings withdrawal notice (days)'];

    protected $fillable = ['club_id', 'proposed_by', 'rules', 'base_version', 'status', 'rationale', 'reviewed_by', 'reviewed_at', 'review_reason'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'reviewed_at' => 'datetime', 'base_version' => 'integer'];
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
