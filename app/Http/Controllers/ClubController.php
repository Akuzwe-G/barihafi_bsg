<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\ClubInvitation;
use App\Models\ClubMembership;
use App\Models\PlatformActivity;
use App\Support\ClubAdministration;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClubController extends Controller
{
    public function dashboard(Request $request): View|RedirectResponse
    {
        if ($request->user()->is_system_admin) {
            return redirect()->route('platform');
        }
        $clubs = $request->user()->accessibleClubs()->get();
        if ($clubs->count() === 1) {
            return redirect()->route($request->user()->clubLandingRoute($clubs->first()), $clubs->first());
        }

        return view('selection', ['clubs' => $clubs, 'memberships' => $request->user()->memberships()->with('club')->get(), 'invitations' => ClubInvitation::where('phone', $request->user()->phone)->whereNull('accepted_at')->where('expires_at', '>', now())->whereHas('club', fn ($query) => $query->where('is_active', true))->with('club')->get()]);
    }

    public function store(Request $request, ClubAdministration $administration): RedirectResponse
    {
        return app(AdminClubController::class)->store($request, $administration);
    }

    public function update(Request $request, Club $club, ClubAdministration $administration): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:150', 'is_active' => 'required|boolean', 'confirmed' => 'required|accepted', 'reason' => [Rule::requiredIf(! $request->boolean('is_active')), 'nullable', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $club, $administration, $data): void {
            $administration->update($request->user(), $club, ['name' => $data['name']]);
            $administration->changeStatus($request->user(), $club, $request->boolean('is_active') ? 'active' : 'suspended', $data['reason'] ?? null);
        });

        return back()->with('status', 'Club updated.');
    }

    public function assign(Request $request, Club $club, ClubAdministration $administration): RedirectResponse
    {
        return app(AdminClubController::class)->assign($request, $club, $administration);
    }

    public function onboarding(Request $request, Club $club): View
    {
        Gate::authorize('onboard', $club);

        return view('club-onboarding', ['club' => $club]);
    }

    public function show(Request $request, Club $club): View
    {
        Gate::authorize('view', $club);
        $membership = $club->memberships()->where('user_id', $request->user()->id)->firstOrFail();

        return view('club', ['club' => $club, 'membership' => $membership]);
    }

    public function members(Club $club): View
    {
        Gate::authorize('manage', $club);

        return view('members', ['club' => $club, 'memberships' => $club->memberships()->with('user')->get()]);
    }

    public function member(Request $request, Club $club, ClubMembership $membership): RedirectResponse
    {
        Gate::authorize('manage', $club);
        abort_unless($membership->club_id === $club->id, 404);
        $data = $request->validate(['roles' => ['required', 'array', 'min:1'], 'roles.*' => [Rule::in(ClubMembership::ROLES)], 'status' => ['required', Rule::in(['unchanged', 'pending', 'suspended', 'revoked'])]]);
        DB::transaction(function () use ($request, $membership, $data, $club): void {
            $updates = ['roles' => array_values(array_unique([...$data['roles'], 'Member']))];
            if ($data['status'] !== 'unchanged') {
                $updates['status'] = $data['status'];
                $club->joinRequests()->where('user_id', $membership->user_id)->update(['status' => $data['status']]);
            }
            $membership->update($updates);
            PlatformActivity::record($request->user(), 'club.membership_updated', $membership->user, $club, ['status' => $membership->fresh()->status, 'roles' => $membership->fresh()->roles]);
        });

        return back()->with('status', 'Membership updated. Activation is unavailable until financial verification exists.');
    }

    public function invite(Request $request, Club $club): RedirectResponse
    {
        Gate::authorize('administer', $club);
        $data = $request->validate(['phone' => ['required', 'string', 'max:40']]);
        $token = Str::random(64);
        DB::transaction(function () use ($request, $club, $data, $token): void {
            $club->invitations()->create(['phone' => Phone::normalize($data['phone']), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7)]);
            PlatformActivity::record($request->user(), 'club.invitation_created', $club, $club);
        });

        return back()->with('invitation_url', route('join', ['invitation' => $token]))->with('status', 'Invitation expires in seven days. Share the link privately with the intended recipient.');
    }
}
