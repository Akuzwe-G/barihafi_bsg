<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\CommitteeMembershipChange;
use App\Models\FinancialRuleProposal;
use App\Support\FinancialPolicyWorkflow;
use App\Support\FinancialRuleSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinancialSettingsController extends Controller
{
    public function index(Club $club, string $page = 'overview'): View
    {
        abort_unless(in_array($page, ['overview', 'shares', 'savings', 'loans', 'governance', 'create', 'pending', 'history', 'committee'], true), 404);
        Gate::authorize(match ($page) {
            'create' => 'financial_settings.propose',
            'history' => 'financial_settings.history',
            'committee' => 'committee.view',
            default => 'financial_settings.view',
        }, $club);
        $workflow = app(FinancialPolicyWorkflow::class);

        return view('clubs.financial-settings', [
            'club' => $club, 'page' => $page, 'current' => $workflow->current($club),
            'fields' => FinancialRuleSchema::fields(),
            'proposals' => $club->ruleProposals()->when($page === 'overview', fn ($query) => $query->whereIn('status', ['draft', 'submitted', 'scheduled']))->with(['proposer', 'reviewer'])->latest()->paginate(15),
            'versions' => $club->policyVersions()->with('approver')->orderByDesc('version')->get(),
            'committee' => $club->memberships()->where('is_committee_approver', true)->with('user')->get(),
            'candidates' => $club->memberships()->where(fn ($query) => $query->where('is_committee_approver', true)->orWhere(fn ($query) => $query->where('admission_status', 'approved')->whereIn('status', ['active', 'pending'])->whereJsonContains('roles', 'Committee Member')->whereHas('user', fn ($userQuery) => $userQuery->where('account_status', 'active'))))->with('user')->get(),
            'committeeChanges' => CommitteeMembershipChange::where('club_id', $club->id)->with(['membership.user', 'proposer', 'reviewer'])->latest()->get(),
            'readiness' => $workflow->committeeReadiness($club, [auth()->id()]),
            'independentApprover' => count($workflow->independentPresidents($club, [auth()->id()])) > 0,
        ]);
    }

    public function show(Club $club, FinancialRuleProposal $proposal): View
    {
        Gate::authorize('financial_settings.view', $club);
        abort_unless($proposal->club_id === $club->id, 404);

        return view('clubs.financial-proposal', ['club' => $club, 'proposal' => $proposal->load(['proposer', 'reviewer']), 'fields' => FinancialRuleSchema::fields(), 'independentApprover' => count(app(FinancialPolicyWorkflow::class)->independentPresidents($club, [$proposal->proposed_by])) > 0]);
    }

    public function store(Request $request, Club $club, bool $submit = false): RedirectResponse
    {
        Gate::authorize('financial_settings.propose', $club);
        $data = $request->validate(['base_version' => 'required|integer|min:0', 'rationale' => 'required|string|max:2000', 'effective_at' => 'nullable|date|after_or_equal:today', 'rules' => 'required|array|min:1']);
        if ($submit) {
            $data['rules'] = array_filter($data['rules'], fn ($value) => $value !== null && $value !== '');
        }
        $proposal = app(FinancialPolicyWorkflow::class)->draft($request->user(), $club, $data, $submit);

        return redirect()->route('clubs.financial.proposals.show', [$club, $proposal])->with('status', $submit ? 'Proposal submitted for independent President review.' : 'Draft saved. Effective settings have not changed.');
    }

    public function submit(Request $request, Club $club, FinancialRuleProposal $proposal): RedirectResponse
    {
        app(FinancialPolicyWorkflow::class)->submit($request->user(), $club, $proposal);

        return back()->with('status', 'Proposal submitted for independent review.');
    }

    public function cancel(Request $request, Club $club, FinancialRuleProposal $proposal): RedirectResponse
    {
        app(FinancialPolicyWorkflow::class)->cancel($request->user(), $club, $proposal);

        return back()->with('status', 'Proposal cancelled.');
    }

    public function review(Request $request, Club $club, FinancialRuleProposal $proposal): RedirectResponse
    {
        Gate::authorize('financial_settings.approve', $club);
        abort_unless($proposal->club_id === $club->id, 404);
        abort_if($proposal->proposed_by === $request->user()->id, 403);
        app(FinancialPolicyWorkflow::class)->review($request->user(), $club, $proposal, $this->decision($request));

        return back()->with('status', 'Independent review recorded.');
    }

    public function nominate(Request $request, Club $club): RedirectResponse
    {
        Gate::authorize('committee.manage', $club);
        $data = $request->validate(['club_membership_id' => ['required', 'integer', Rule::exists('club_memberships', 'id')->where('club_id', $club->id)], 'operation' => ['required', Rule::in(['appoint', 'remove'])], 'reason' => 'required|string|max:2000']);
        app(FinancialPolicyWorkflow::class)->nominate($request->user(), $club, $data);

        return back()->with('status', 'Committee change submitted for independent President approval.');
    }

    public function reviewCommittee(Request $request, Club $club, CommitteeMembershipChange $change): RedirectResponse
    {
        Gate::authorize('financial_settings.approve', $club);
        abort_unless($change->club_id === $club->id, 404);
        app(FinancialPolicyWorkflow::class)->reviewCommittee($request->user(), $club, $change, $this->decision($request));

        return back()->with('status', 'Committee decision recorded.');
    }

    private function decision(Request $request): array
    {
        return $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => 'required_if:decision,rejected|nullable|string|max:2000', 'confirmed' => 'required|accepted']);
    }
}
