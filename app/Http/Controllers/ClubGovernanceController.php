<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\FinancialRuleProposal;
use App\Models\PlatformActivity;
use App\Support\ClubMembershipWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ClubGovernanceController extends Controller
{
    public function index(Club $club): View
    {
        return app(FinancialSettingsController::class)->index($club);
    }

    public function propose(Request $request, Club $club): RedirectResponse
    {
        return app(FinancialSettingsController::class)->store($request, $club, true);
    }

    public function review(Request $request, Club $club, FinancialRuleProposal $proposal): RedirectResponse
    {
        return app(FinancialSettingsController::class)->review($request, $club, $proposal);
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
            if (! empty($data['committee_approvers'])) {
                throw ValidationException::withMessages(['committee_approvers' => 'Committee appointments require a club nomination and independent President approval.']);
            }
            $club->delegated_roles = array_values(array_unique([...$data['delegated_roles'], 'Member']));
            $club->save();
            PlatformActivity::record($request->user(), 'club.governance_permissions_updated', $club, $club, ['roles' => $club->delegated_roles, 'fields' => ['delegated_roles']]);
        });

        return back()->with('status', 'Club role delegation updated. Committee changes require independent club approval.');
    }

    public function platformRoles(Request $request, Club $club, ClubMembership $membership, ClubMembershipWorkflow $workflow): RedirectResponse
    {
        abort_unless($membership->club_id === $club->id, 404);
        $data = $request->validate(['roles' => 'required|array|min:1', 'roles.*' => [Rule::in(ClubMembership::ROLES)], 'confirmed' => 'required|accepted']);
        $workflow->updateMembership($request->user(), $club, $membership, ['roles' => $data['roles'], 'status' => 'unchanged'], true);

        return back()->with('status', 'Platform-authorised club roles updated. Financial membership status is unchanged.');
    }
}
