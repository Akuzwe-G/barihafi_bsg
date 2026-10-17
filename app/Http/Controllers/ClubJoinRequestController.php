<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\ClubJoinRequest;
use App\Support\ClubMembershipWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClubJoinRequestController extends Controller
{
    public function index(Request $request, Club $club): View
    {
        Gate::authorize('administer', $club);
        $filters = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])]]);
        $requests = $club->joinRequests()->with(['user', 'reviewer', 'history.actor'])->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->latest()->paginate(15)->withQueryString();

        return view('clubs.requests', compact('club', 'requests'));
    }

    public function review(Request $request, Club $club, ClubJoinRequest $joinRequest, ClubMembershipWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('administer', $club);
        abort_unless($joinRequest->club_id === $club->id, 404);
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => 'required_if:decision,rejected|nullable|string|max:1000', 'confirmed' => 'required|accepted']);
        $workflow->review($request->user(), $club, $joinRequest, $data['decision'], $data['reason'] ?? null);

        return back()->with('status', 'Request reviewed. Approval never activates financial membership.');
    }

    public function mine(Request $request): View
    {
        return view('my-requests', ['requests' => ClubJoinRequest::where('user_id', $request->user()->id)->with(['club', 'history.actor'])->latest()->paginate(15)]);
    }
}
