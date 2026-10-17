<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\ClubNotification;
use App\Models\FinancialRuleProposal;
use App\Models\PlatformActivity;
use App\Support\ClubMembershipWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ClubGovernanceController extends Controller
{
    public function index(Club $club): View
    {
        Gate::authorize('govern', $club);

        return view('clubs.governance', ['club' => $club, 'current' => $club->policyVersions()->latest('version')->with('approver')->first(), 'proposals' => $club->ruleProposals()->with(['proposer', 'reviewer'])->latest()->paginate(10), 'versions' => $club->policyVersions()->with('approver')->orderByDesc('version')->get(), 'approvers' => $club->memberships()->where('is_committee_approver', true)->whereIn('status', ['active', 'pending'])->whereHas('user', fn ($q) => $q->where('account_status', 'active'))->with('user')->get()]);
    }

    public function propose(Request $request, Club $club): RedirectResponse
    {
        Gate::authorize('administer', $club);
        $data = $request->validate(['base_version' => 'required|integer|min:0', 'rationale' => 'required|string|max:2000', 'rules' => ['required', 'array:'.implode(',', array_keys(FinancialRuleProposal::RULE_LABELS)), 'min:1'], 'rules.share_price' => ['nullable', 'numeric', 'min:0.01', 'max:1000000000', 'regex:/^[0-9]{1,10}(\.[0-9]{1,2})?$/'], 'rules.minimum_membership_shares' => 'nullable|integer|min:1|max:1000000', 'rules.loan_multiplier' => 'nullable|numeric|min:0.01|max:100', 'rules.interest_rate' => 'nullable|numeric|min:0|max:1000', 'rules.interest_method' => ['nullable', Rule::in(['flat', 'reducing_balance'])], 'rules.loan_duration_months' => 'nullable|integer|min:1|max:120', 'rules.repayment_frequency' => ['nullable', Rule::in(['weekly', 'monthly', 'quarterly'])], 'rules.savings_withdrawal_notice_days' => 'nullable|integer|min:0|max:3650']);
        $rules = array_filter($data['rules'], fn ($value) => $value !== null && $value !== '');
        if ($rules === []) {
            throw ValidationException::withMessages(['rules' => 'Provide at least one proposed rule.']);
        }
        DB::transaction(function () use ($request, $club, $data, $rules): void {
            $club = Club::whereKey($club->id)->lockForUpdate()->firstOrFail();
            $current = $club->policyVersions()->latest('version')->first();
            if ((int) $data['base_version'] !== ($current?->version ?? 0)) {
                throw ValidationException::withMessages(['base_version' => 'The approved policy has changed. Reload before proposing a new version.']);
            }
            $proposal = $club->ruleProposals()->create(['proposed_by' => $request->user()->id, 'rules' => array_merge($current?->rules ?? [], $rules), 'base_version' => $current?->version ?? 0, 'rationale' => $data['rationale'], 'status' => 'pending']);
            PlatformActivity::record($request->user(), 'club.financial_rules_proposed', $proposal, $club, ['fields' => array_keys($rules), 'status' => 'pending']);
            foreach ($club->memberships()->whereJsonContains('roles', 'President')->whereIn('status', ['active', 'pending'])->get() as $president) {
                if ($president->status === 'active' || $president->has_governance_access) {
                    ClubNotification::send($club, $president->user_id, 'rules_proposed', 'A financial-rule proposal is awaiting independent President review.');
                }
            }
        });

        return back()->with('status', 'Proposal submitted. The current approved policy has not changed.');
    }

    public function review(Request $request, Club $club, FinancialRuleProposal $proposal): RedirectResponse
    {
        Gate::authorize('reviewRules', $club);
        abort_unless($proposal->club_id === $club->id, 404);
        abort_if($proposal->proposed_by === $request->user()->id, 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => 'required_if:decision,rejected|nullable|string|max:2000', 'confirmed' => 'required|accepted']);
        DB::transaction(function () use ($request, $club, $proposal, $data): void {
            $club = Club::whereKey($club->id)->lockForUpdate()->firstOrFail();
            $proposal = $club->ruleProposals()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            if ($proposal->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'This proposal has already been reviewed.']);
            }
            $current = (int) ($club->policyVersions()->max('version') ?? 0);
            if ($data['decision'] === 'approved' && $proposal->base_version !== $current) {
                throw ValidationException::withMessages(['decision' => 'A newer policy has already been approved. Request a fresh proposal.']);
            }
            $proposal->update(['status' => $data['decision'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_reason' => $data['reason'] ?? null]);
            if ($data['decision'] === 'approved') {
                $club->policyVersions()->create(['version' => $current + 1, 'financial_rule_proposal_id' => $proposal->id, 'rules' => $proposal->rules, 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            }
            PlatformActivity::record($request->user(), 'club.financial_rules_'.$data['decision'], $proposal, $club, ['status' => $data['decision'], 'fields' => array_keys($proposal->rules)]);
            ClubNotification::send($club, $proposal->proposed_by, 'rules_'.$data['decision'], 'Your financial-rule proposal was '.$data['decision'].'. No financial transaction or membership activation was performed.');
        });

        return back()->with('status', 'Proposal reviewed. Policy versions are recorded without executing financial calculations.');
    }

    public function platform(Club $club): View
    {
        return view('admin.clubs.governance', ['club' => $club, 'memberships' => $club->memberships()->with('user')->get()]);
    }

    public function platformUpdate(Request $request, Club $club): RedirectResponse
    {
        $data = $request->validate(['delegated_roles' => 'required|array|min:1', 'delegated_roles.*' => [Rule::in(['Member', 'Committee Member', 'President', 'Accountant'])], 'committee_approvers' => 'nullable|array', 'committee_approvers.*' => ['integer', Rule::exists('club_memberships', 'id')->where('club_id', $club->id)], 'confirmed' => 'required|accepted']);
        DB::transaction(function () use ($request, $club, $data): void {
            $club = Club::whereKey($club->id)->lockForUpdate()->firstOrFail();
            $ids = $data['committee_approvers'] ?? [];
            $eligible = $club->memberships()->whereIn('id', $ids)->whereIn('status', ['active', 'pending'])->whereJsonContains('roles', 'Committee Member')->whereHas('user', fn ($q) => $q->where('account_status', 'active'))->count();
            if ($eligible !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['committee_approvers' => 'Designated approvers must be available Committee Members of this club.']);
            }
            $club->delegated_roles = array_values(array_unique([...$data['delegated_roles'], 'Member']));
            $club->save();
            $club->memberships()->update(['is_committee_approver' => false]);
            if ($ids !== []) {
                $club->memberships()->whereIn('id', $ids)->update(['is_committee_approver' => true]);
            }
            PlatformActivity::record($request->user(), 'club.governance_permissions_updated', $club, $club, ['roles' => $club->delegated_roles, 'fields' => ['delegated_roles', 'committee_approvers']]);
        });

        return back()->with('status', 'Club governance delegation and committee designations updated. No financial authority or transaction was executed.');
    }

    public function platformRoles(Request $request, Club $club, ClubMembership $membership, ClubMembershipWorkflow $workflow): RedirectResponse
    {
        abort_unless($membership->club_id === $club->id, 404);
        $data = $request->validate(['roles' => 'required|array|min:1', 'roles.*' => [Rule::in(ClubMembership::ROLES)], 'confirmed' => 'required|accepted']);
        $workflow->updateMembership($request->user(), $club, $membership, ['roles' => $data['roles'], 'status' => 'unchanged'], true);

        return back()->with('status', 'Platform-authorised club roles updated. Financial membership status is unchanged.');
    }
}
