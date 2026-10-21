<?php

namespace App\Support;

use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\ClubNotification;
use App\Models\ClubPolicyVersion;
use App\Models\CommitteeMembershipChange;
use App\Models\FinancialRuleProposal;
use App\Models\PlatformActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class FinancialPolicyWorkflow
{
    public function current(Club $club): ?ClubPolicyVersion
    {
        return $club->policyVersions()->where('active_club_id', $club->id)->first();
    }

    private function lock(Club $club): Club
    {
        $club = Club::whereKey($club->id)->lockForUpdate()->firstOrFail();
        abort_unless($club->is_active, 403, 'This club is unavailable for governance changes.');

        return $club;
    }

    public function draft(User $actor, Club $club, array $data, bool $submit = false): FinancialRuleProposal
    {
        return DB::transaction(function () use ($actor, $club, $data, $submit): FinancialRuleProposal {
            $club = $this->lock($club);
            Gate::forUser($actor)->authorize('financial_settings.propose', $club);
            $current = $this->current($club);
            if ((int) $data['base_version'] !== ($current?->version ?? 0)) {
                throw ValidationException::withMessages(['base_version' => 'The effective policy has changed. Reload the form.']);
            }
            $rules = FinancialRuleSchema::snapshot($data['rules'], $current?->rules ?? []);
            $proposal = $club->ruleProposals()->create(['proposed_by' => $actor->id, 'rules' => $rules, 'existing_rules' => $current?->rules ?? [], 'base_version' => $current?->version ?? 0, 'status' => 'draft', 'rationale' => $data['rationale'], 'effective_at' => $data['effective_at'] ?? now(), 'is_baseline' => $current === null]);
            PlatformActivity::record($actor, 'club.policy_drafted', $proposal, $club, ['status' => 'draft', 'fields' => array_keys($rules)]);
            if ($submit) {
                $this->submit($actor, $club, $proposal);
            }

            return $proposal->refresh();
        });
    }

    public function independentPresidents(Club $club, array $excluded = []): array
    {
        return $club->memberships()->whereJsonContains('roles', 'President')->whereIn('status', ['active', 'pending'])->whereHas('user', fn ($query) => $query->where('account_status', 'active'))->with('user')->whereNotIn('user_id', $excluded)->get()->filter(fn (ClubMembership $membership) => Gate::forUser($membership->user)->allows('financial_settings.approve', $club))->pluck('user_id')->all();
    }

    public function submit(User $actor, Club $club, FinancialRuleProposal $proposal): void
    {
        DB::transaction(function () use ($actor, $club, $proposal): void {
            $club = $this->lock($club);
            Gate::forUser($actor)->authorize('financial_settings.propose', $club);
            $proposal = $club->ruleProposals()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            abort_unless($proposal->proposed_by === $actor->id, 403);
            if ($proposal->status !== 'draft') {
                throw ValidationException::withMessages(['proposal' => 'Only a draft may be submitted.']);
            }
            if ($proposal->base_version !== ($this->current($club)?->version ?? 0)) {
                throw ValidationException::withMessages(['base_version' => 'This draft is stale. Create a proposal from the current policy.']);
            }
            if ($club->ruleProposals()->whereKeyNot($proposal->id)->whereIn('status', ['submitted', 'pending', 'approved', 'scheduled'])->exists()) {
                throw ValidationException::withMessages(['proposal' => 'Another proposal is awaiting review or activation. Resolve it first.']);
            }
            $presidents = $this->independentPresidents($club, [$actor->id]);
            if ($presidents === []) {
                throw ValidationException::withMessages(['proposal' => 'No independent authorised President is available. Assign an eligible President before submission.']);
            }
            $proposal->update(['status' => 'submitted', 'submitted_at' => now()]);
            PlatformActivity::record($actor, 'club.financial_rules_proposed', $proposal, $club, ['status' => 'submitted']);
            foreach ($presidents as $id) {
                ClubNotification::send($club, $id, 'rules_proposed', 'A financial policy proposal is awaiting your independent review.');
            }
        });
    }

    public function cancel(User $actor, Club $club, FinancialRuleProposal $proposal): void
    {
        DB::transaction(function () use ($actor, $club, $proposal): void {
            $club = $this->lock($club);
            Gate::forUser($actor)->authorize('financial_settings.propose', $club);
            $proposal = $club->ruleProposals()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            abort_unless($proposal->proposed_by === $actor->id, 403);
            if (! in_array($proposal->status, ['draft', 'submitted'], true)) {
                throw ValidationException::withMessages(['proposal' => 'Only draft or submitted proposals can be cancelled.']);
            }
            $proposal->update(['status' => 'cancelled']);
            PlatformActivity::record($actor, 'club.policy_cancelled', $proposal, $club, ['status' => 'cancelled']);
            foreach ($this->independentPresidents($club, [$actor->id]) as $id) {
                ClubNotification::send($club, $id, 'rules_cancelled', 'A financial policy proposal was cancelled by its proposer.');
            }
        });
    }

    public function review(User $actor, Club $club, FinancialRuleProposal $proposal, array $data): void
    {
        DB::transaction(function () use ($actor, $club, $proposal, $data): void {
            $club = $this->lock($club);
            Gate::forUser($actor)->authorize('financial_settings.approve', $club);
            $proposal = $club->ruleProposals()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            abort_if($proposal->proposed_by === $actor->id, 403);
            if (! in_array($proposal->status, ['submitted', 'pending'], true)) {
                throw ValidationException::withMessages(['decision' => 'This proposal is no longer awaiting review.']);
            }
            $current = $this->current($club);
            if ($data['decision'] === 'approved' && ($proposal->base_version !== ($current?->version ?? 0) || $club->ruleProposals()->where('status', 'scheduled')->exists())) {
                throw ValidationException::withMessages(['decision' => 'A newer or scheduled policy conflicts with this proposal. Create a fresh proposal.']);
            }
            $proposal->update(['status' => $data['decision'], 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_reason' => $data['reason'] ?? null]);
            PlatformActivity::record($actor, 'club.financial_rules_'.$data['decision'], $proposal, $club, ['status' => $data['decision']]);
            if ($data['decision'] === 'approved') {
                $rules = FinancialRuleSchema::snapshot($proposal->rules);
                $version = $club->policyVersions()->create(['version' => ((int) $club->policyVersions()->max('version')) + 1, 'financial_rule_proposal_id' => $proposal->id, 'rules' => $rules, 'approved_by' => $actor->id, 'approved_at' => now(), 'effective_from' => $proposal->effective_at ?? now(), 'is_baseline' => $current === null]);
                if ($version->effective_from->isFuture()) {
                    $proposal->update(['status' => 'scheduled']);
                    PlatformActivity::record($actor, 'club.policy_scheduled', $proposal, $club, ['status' => 'scheduled']);
                } else {
                    $this->activate($club, $version, $actor);
                }
            }
            ClubNotification::send($club, $proposal->proposed_by, 'rules_'.$data['decision'], 'Your financial policy proposal was '.$data['decision'].'. Current state: '.$proposal->fresh()->status.'.');
        });
    }

    private function activate(Club $club, ClubPolicyVersion $version, ?User $actor): void
    {
        $current = $this->current($club);
        $at = now();
        if ($current) {
            // Lifecycle metadata alone changes; the approved snapshot and approval identity remain immutable.
            DB::table('club_policy_versions')->where('id', $current->id)->update(['active_club_id' => null, 'effective_to' => $at]);
            FinancialRuleProposal::whereKey($current->financial_rule_proposal_id)->update(['status' => 'superseded']);
            PlatformActivity::record($actor, 'club.policy_superseded', $current, $club, ['status' => 'superseded']);
        }
        DB::table('club_policy_versions')->where('id', $version->id)->update(['active_club_id' => $club->id, 'effective_from' => $at]);
        FinancialRuleProposal::whereKey($version->financial_rule_proposal_id)->update(['status' => 'effective']);
        PlatformActivity::record($actor, 'club.policy_effective', $version, $club, ['status' => 'effective']);
        ClubNotification::administrators($club, 'policy_effective', 'Financial policy version '.$version->version.' is now effective.');
    }

    public function activateDue(): int
    {
        $count = 0;
        foreach (FinancialRuleProposal::where('status', 'scheduled')->where('effective_at', '<=', now())->pluck('id') as $id) {
            $count += DB::transaction(function () use ($id): int {
                $candidate = FinancialRuleProposal::findOrFail($id);
                $club = Club::whereKey($candidate->club_id)->lockForUpdate()->firstOrFail();
                $proposal = $club->ruleProposals()->whereKey($id)->lockForUpdate()->firstOrFail();
                if (! $club->is_active || $proposal->status !== 'scheduled' || $proposal->effective_at->isFuture()) {
                    return 0;
                }
                if ($proposal->base_version !== ($this->current($club)?->version ?? 0)) {
                    return 0;
                }
                $version = $club->policyVersions()->where('financial_rule_proposal_id', $id)->firstOrFail();
                $this->activate($club, $version, null);

                return 1;
            });
        }

        return $count;
    }

    public function nominate(User $actor, Club $club, array $data): void
    {
        DB::transaction(function () use ($actor, $club, $data): void {
            $club = $this->lock($club);
            Gate::forUser($actor)->authorize('committee.manage', $club);
            $membership = $club->memberships()->whereKey($data['club_membership_id'])->lockForUpdate()->firstOrFail();
            if (($data['operation'] === 'appoint' && ! $this->eligibleCommittee($membership)) || ($membership->is_committee_approver === ($data['operation'] === 'appoint'))) {
                throw ValidationException::withMessages(['club_membership_id' => 'Choose an eligible Committee Member and a change to their current designation.']);
            }
            if (CommitteeMembershipChange::where('club_id', $club->id)->where('club_membership_id', $membership->id)->where('status', 'submitted')->exists()) {
                throw ValidationException::withMessages(['club_membership_id' => 'This member already has a pending committee change.']);
            }
            $presidents = $this->independentPresidents($club, [$actor->id, $membership->user_id]);
            if ($presidents === []) {
                throw ValidationException::withMessages(['club_membership_id' => 'An independent President other than the proposer and affected member is required.']);
            }
            $change = CommitteeMembershipChange::create([...$data, 'club_id' => $club->id, 'proposed_by' => $actor->id, 'status' => 'submitted']);
            PlatformActivity::record($actor, 'club.committee_nominated', $change, $club, ['status' => 'submitted']);
            foreach ($presidents as $id) {
                ClubNotification::send($club, $id, 'committee_review', 'A committee designation change awaits independent review.');
            }
        });
    }

    public function reviewCommittee(User $actor, Club $club, CommitteeMembershipChange $change, array $data): void
    {
        DB::transaction(function () use ($actor, $club, $change, $data): void {
            $club = $this->lock($club);
            Gate::forUser($actor)->authorize('financial_settings.approve', $club);
            $change = CommitteeMembershipChange::where('club_id', $club->id)->whereKey($change->id)->lockForUpdate()->firstOrFail();
            $membership = $club->memberships()->whereKey($change->club_membership_id)->lockForUpdate()->firstOrFail();
            abort_if(in_array($actor->id, [$change->proposed_by, $membership->user_id], true), 403);
            if ($change->status !== 'submitted') {
                throw ValidationException::withMessages(['decision' => 'This change has already been reviewed.']);
            }
            if ($data['decision'] === 'approved') {
                if (($change->operation === 'appoint' && ! $this->eligibleCommittee($membership)) || $membership->is_committee_approver === ($change->operation === 'appoint')) {
                    throw ValidationException::withMessages(['decision' => 'Committee eligibility or designation changed. Submit a fresh nomination.']);
                }
                $membership->update(['is_committee_approver' => $change->operation === 'appoint']);
            }
            $change->update(['status' => $data['decision'], 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_reason' => $data['reason'] ?? null]);
            PlatformActivity::record($actor, 'club.committee_'.$data['decision'], $change, $club, ['status' => $data['decision']]);
            foreach (array_unique([$change->proposed_by, $membership->user_id]) as $id) {
                ClubNotification::send($club, $id, 'committee_'.$data['decision'], 'A committee '.$change->operation.' change was '.$data['decision'].'.');
            }
        });
    }

    private function eligibleCommittee(ClubMembership $membership): bool
    {
        return $membership->admission_status === 'approved' && in_array($membership->status, ['active', 'pending'], true) && in_array('Committee Member', $membership->roles, true) && $membership->user->account_status === 'active';
    }

    /** @return array{eligible_ids: array, required: int, available: bool} */
    public function committeeReadiness(Club $club, array $conflictedUserIds = []): array
    {
        $rules = $this->current($club)?->rules ?? [];
        $all = $club->memberships()->where('is_committee_approver', true)->with('user')->get()->filter(fn (ClubMembership $m) => $this->eligibleCommittee($m));
        $ids = $all->whereNotIn('user_id', $conflictedUserIds)->pluck('user_id')->all();
        $required = (int) ($rules['committee_quorum'] ?? 2);

        return ['eligible_ids' => $ids, 'required' => $required, 'available' => $club->is_active && $this->current($club) !== null && $all->count() >= ($rules['committee_minimum_members'] ?? 3) && count($ids) >= $required];
    }

    /** Future financial workflows must supply all conflicted actors and distinct approval identities. */
    public function assertCommitteeApprovals(Club $club, array $conflictedUserIds, array $approvedUserIds): void
    {
        $readiness = $this->committeeReadiness($club, $conflictedUserIds);
        $approvals = array_unique($approvedUserIds);
        if (! $readiness['available'] || count($approvals) < $readiness['required'] || array_diff($approvals, $readiness['eligible_ids']) !== []) {
            throw ValidationException::withMessages(['committee' => 'Insufficient eligible independent committee approvals. Normal financial role controls remain required.']);
        }
    }

    /** Future callers must check these states under their own club/member transaction lock. */
    public function assertNoConcurrentLoan(bool $outstanding, bool $pendingApplication, bool $undisbursedApproval): void
    {
        if ($outstanding || $pendingApplication || $undisbursedApproval) {
            throw ValidationException::withMessages(['loan' => 'An outstanding loan, pending application or undisbursed approval blocks a new loan.']);
        }
    }
}
