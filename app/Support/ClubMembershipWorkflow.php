<?php

namespace App\Support;

use App\Models\Club;
use App\Models\ClubJoinRequest;
use App\Models\ClubMembership;
use App\Models\ClubNotification;
use App\Models\PlatformActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClubMembershipWorkflow
{
    public function submit(User $user, Club $club): ?ClubJoinRequest
    {
        return DB::transaction(function () use ($user, $club): ?ClubJoinRequest {
            $membership = $club->memberships()->firstOrCreate(['user_id' => $user->id], ['status' => 'pending', 'roles' => ['Member'], 'admission_status' => 'pending_review', 'joined_at' => now()]);
            if (in_array($membership->status, ['active', 'suspended', 'revoked'], true)) {
                return null;
            }
            $request = $club->joinRequests()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($membership->admission_status === 'approved' && ! $request) {
                return null;
            }
            if ($request && $request->status !== 'rejected') {
                return $request;
            }
            if (! $request) {
                $request = $club->joinRequests()->create(['user_id' => $user->id, 'status' => 'pending']);
            } else {
                $request->update(['status' => 'pending', 'reviewed_by' => null, 'reviewed_at' => null, 'rejection_reason' => null]);
            }
            $membership->update(['admission_status' => 'pending_review']);
            $request->history()->create(['club_id' => $club->id, 'actor_id' => $user->id, 'status' => 'pending']);
            PlatformActivity::record($user, 'club.join_requested', $request, $club, ['status' => 'pending']);
            ClubNotification::administrators($club, 'join_request', 'A new membership request is awaiting review.');
            ClubNotification::send($club, $user->id, 'join_submitted', 'Your join request is awaiting club review. Financial membership is not active.');

            return $request;
        });
    }

    public function register(User $actor, Club $club, array $data): User
    {
        return DB::transaction(function () use ($actor, $club, $data): User {
            if (User::where('phone', $data['phone'])->exists() || (! empty($data['email']) && User::where('email', $data['email'])->exists())) {
                throw ValidationException::withMessages(['phone' => 'This account already exists. Send a club invitation instead; its password will not be changed.']);
            }
            $user = new User(array_intersect_key($data, array_flip(['name', 'phone', 'email', 'password'])));
            $user->must_change_password = true;
            $user->save();
            $club->memberships()->create(['user_id' => $user->id, 'status' => 'pending', 'admission_status' => 'approved', 'roles' => ['Member'], 'joined_at' => now()]);
            PlatformActivity::record($actor, 'club.member_registered', $user, $club, ['status' => 'pending']);
            ClubNotification::send($club, $user->id, 'member_registered', 'Your account was created by the club. Change your temporary password at sign-in. Financial membership remains pending.');

            return $user;
        });
    }

    public function review(User $actor, Club $club, ClubJoinRequest $request, string $decision, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $club, $request, $decision, $reason): void {
            $request = $club->joinRequests()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'This request has already been reviewed.']);
            }
            $membership = $club->memberships()->firstOrCreate(['user_id' => $request->user_id], ['status' => 'pending', 'roles' => ['Member'], 'joined_at' => now(), 'admission_status' => 'pending_review']);
            if (in_array($membership->status, ['suspended', 'revoked'], true)) {
                throw ValidationException::withMessages(['decision' => 'Resolve the suspended or revoked membership separately before reviewing this request.']);
            }
            $request->update(['status' => $decision, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'rejection_reason' => $decision === 'rejected' ? $reason : null]);
            if ($membership->status === 'pending') {
                $membership->update(['admission_status' => $decision]);
            }
            $request->history()->create(['club_id' => $club->id, 'actor_id' => $actor->id, 'status' => $decision, 'reason' => $reason]);
            PlatformActivity::record($actor, 'club.join_request_'.$decision, $request, $club, ['status' => $decision]);
            ClubNotification::send($club, $request->user_id, 'join_'.$decision, $decision === 'approved' ? 'Your join request was approved. Financial membership remains pending until verified contributions meet the membership threshold.' : 'Your join request was rejected. View your request history for the reason.');
        });
    }

    public function updateMembership(User $actor, Club $club, ClubMembership $membership, array $data, bool $platform = false): void
    {
        abort_if($platform && ! $actor->hasPlatformRole(User::SYSTEM_ADMIN_ROLE), 403);
        DB::transaction(function () use ($actor, $club, $membership, $data, $platform): void {
            $club = Club::whereKey($club->id)->lockForUpdate()->firstOrFail();
            $membership = $club->memberships()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $old = $membership->roles;
            $roles = array_values(array_unique([...$data['roles'], 'Member']));
            $added = array_diff($roles, $old);
            $removed = array_diff($old, $roles);
            $changed = array_unique([...$added, ...$removed]);
            if (! $platform) {
                abort_if(array_diff($changed, $club->assignableRoles()) !== [] || in_array('Club Administrator', $changed, true), 403);
                abort_if($actor->id === $membership->user_id && array_diff($added, ['Member']) !== [], 403);
            }
            if (in_array('Club Administrator', $removed, true) && ! $club->memberships()->where('id', '!=', $membership->id)->whereIn('status', ['active', 'pending'])->whereJsonContains('roles', 'Club Administrator')->exists()) {
                throw ValidationException::withMessages(['roles' => 'Assign another Club Administrator before removing the last one.']);
            }
            $status = $data['status'] === 'unchanged' ? $membership->status : $data['status'];
            $onboarding = $status === 'pending' && in_array('Club Administrator', $roles, true) && ($membership->has_onboarding_access || ($platform && in_array('Club Administrator', $added, true)));
            $governance = $status === 'pending' && in_array('President', $roles, true) && ($membership->has_governance_access || $platform || in_array('President', $added, true));
            $membership->update(['roles' => $roles, 'status' => $status, 'has_onboarding_access' => $onboarding, 'has_governance_access' => $governance, 'is_committee_approver' => in_array('Committee Member', $roles, true) && in_array($status, ['pending', 'active'], true) && $membership->is_committee_approver]);
            PlatformActivity::record($actor, $changed !== [] ? 'club.roles_changed' : 'club.membership_updated', $membership->user, $club, ['roles' => $roles, 'previous_roles' => $old, 'status' => $status]);
            ClubNotification::send($club, $membership->user_id, 'membership_updated', 'Your club membership status or role assignment was updated. Financial access still depends on active membership.');
        });
    }
}
