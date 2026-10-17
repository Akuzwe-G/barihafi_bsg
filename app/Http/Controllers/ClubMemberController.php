<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\User;
use App\Support\ClubMembershipWorkflow;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ClubMemberController extends Controller
{
    public function index(Request $request, Club $club): View
    {
        Gate::authorize('administer', $club);
        $filters = $request->validate(['search' => 'nullable|string|max:150', 'status' => ['nullable', Rule::in(['active', 'pending', 'suspended', 'revoked'])], 'role' => ['nullable', Rule::in(ClubMembership::ROLES)], 'admission' => ['nullable', Rule::in(['approved', 'pending_review', 'rejected'])]]);
        $memberships = $club->memberships()->with('user')->when($filters['search'] ?? null, fn ($q, $search) => $q->whereHas('user', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'))))->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->when($filters['role'] ?? null, fn ($q, $role) => $q->whereJsonContains('roles', $role))->when($filters['admission'] ?? null, fn ($q, $status) => $q->where('admission_status', $status))->latest()->paginate(15)->withQueryString();

        return view('clubs.members.index', compact('club', 'memberships'));
    }

    public function create(Club $club): View
    {
        Gate::authorize('administer', $club);

        return view('clubs.members.create', compact('club'));
    }

    public function store(Request $request, Club $club, ClubMembershipWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('administer', $club);
        $request->validate(['phone' => 'required|string|max:40', 'email' => 'nullable|string|max:255', 'mode' => ['required', Rule::in(['new', 'existing'])]]);
        $request->merge(['phone' => Phone::normalize($request->input('phone')), 'email' => $request->filled('email') ? strtolower(trim($request->input('email'))) : null]);
        if ($request->input('mode') === 'existing') {
            if (! User::where('phone', $request->input('phone'))->where('account_status', 'active')->exists()) {
                throw ValidationException::withMessages(['phone' => 'Use the phone of an existing active account, or register a new member.']);
            }

            return app(ClubController::class)->invite($request, $club);
        }
        $data = $request->validate(['name' => 'required|string|max:150', 'phone' => 'required|string|max:16', 'email' => 'nullable|email|max:255', 'password' => ['required', 'string', 'confirmed', 'max:72', Password::min(16)->mixedCase()->numbers()->symbols()]]);
        if (strlen($data['password']) > 72 || str_contains($data['password'], "\0")) {
            throw ValidationException::withMessages(['password' => 'Use at most 72 bytes and no null characters.']);
        }
        $user = $workflow->register($request->user(), $club, $data);
        $membership = $club->memberships()->where('user_id', $user->id)->firstOrFail();

        return redirect()->route('clubs.members.show', [$club, $membership])->with('status', 'Member account created. Deliver temporary credentials privately; the user must change the password at first login. Financial membership remains pending.');
    }

    public function show(Club $club, ClubMembership $membership): View
    {
        Gate::authorize('administer', $club);
        abort_unless($membership->club_id === $club->id, 404);
        $membership->load('user');

        return view('clubs.members.show', ['club' => $club, 'membership' => $membership]);
    }

    public function update(Request $request, Club $club, ClubMembership $membership, ClubMembershipWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('administer', $club);
        abort_unless($membership->club_id === $club->id, 404);
        $data = $request->validate(['roles' => 'required|array|min:1', 'roles.*' => [Rule::in(ClubMembership::ROLES)], 'status' => ['required', Rule::in(['unchanged', 'pending', 'suspended', 'revoked'])]]);
        $workflow->updateMembership($request->user(), $club, $membership, $data);

        return back()->with('status', 'Membership and authorised role assignments updated. Financial activation remains unavailable.');
    }
}
