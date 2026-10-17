<?php

namespace App\Support;

use App\Models\AccountSetupInvitation;
use App\Models\Club;
use App\Models\PlatformActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClubAdministration
{
    public function create(User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data): array {
            $setupUrl = null;
            if (($data['administrator_mode'] ?? 'existing') === 'new') {
                if (User::where('phone', $data['administrator_phone'])->exists() || (! empty($data['administrator_email']) && User::where('email', $data['administrator_email'])->exists())) {
                    throw ValidationException::withMessages(['administrator_phone' => 'This account already exists. Select the existing account instead.']);
                }
                $administrator = new User(['name' => $data['administrator_name'], 'phone' => $data['administrator_phone'], 'email' => $data['administrator_email'] ?? null, 'password' => Hash::make(Str::random(64))]);
                $administrator->account_status = 'invited';
                $administrator->save();
                $setupUrl = AccountSetupInvitation::issue($administrator, $actor);
                PlatformActivity::record($actor, 'account.setup_invited', $administrator);
            } else {
                $administrator = $this->existingAdministrator($data);
            }
            $club = Club::create(['name' => $data['name'], 'description' => $data['description'] ?? null, 'contact_phone' => $data['contact_phone'] ?? null, 'status' => $data['status'] ?? 'active', 'join_code' => Str::random(40)]);
            PlatformActivity::record($actor, 'club.created', $club, $club, ['status' => $club->status]);
            $this->assign($actor, $club, $administrator, false, true);

            return ['club' => $club, 'setup_url' => $setupUrl];
        });
    }

    public function existingAdministrator(array $data): User
    {
        $query = User::query()->whereIn('account_status', ['active', 'invited']);
        $administrator = ! empty($data['administrator_id']) ? $query->whereKey($data['administrator_id'])->lockForUpdate()->first() : $query->where('phone', $data['administrator_phone'])->lockForUpdate()->first();
        if (! $administrator) {
            throw ValidationException::withMessages(['administrator_phone' => 'Select an existing available account or invite a new administrator.']);
        }

        return $administrator;
    }

    public function assign(User $actor, Club $club, User $administrator, bool $replace = false, bool $initial = false): void
    {
        $previous = [];
        if ($replace) {
            foreach ($club->memberships()->lockForUpdate()->get() as $membership) {
                if (in_array('Club Administrator', $membership->roles, true)) {
                    $previous[] = $membership->user_id;
                    if ($membership->user_id !== $administrator->id) {
                        $membership->update(['roles' => array_values(array_diff($membership->roles, ['Club Administrator'])), 'has_onboarding_access' => false]);
                    }
                }
            }
        }
        $membership = $club->memberships()->firstOrCreate(['user_id' => $administrator->id], ['status' => 'pending', 'roles' => ['Member'], 'joined_at' => now()]);
        $membership->update(['roles' => array_values(array_unique([...$membership->roles, 'Club Administrator', 'Member'])), 'has_onboarding_access' => $membership->status === 'pending']);
        PlatformActivity::record($actor, $initial ? 'club.initial_administrator_assigned' : 'club.administrator_changed', $club, $club, ['administrator_id' => $administrator->id, 'previous_administrator_ids' => $previous]);
    }

    public function update(User $actor, Club $club, array $data): void
    {
        DB::transaction(function () use ($actor, $club, $data): void {
            $club->fill(array_intersect_key($data, array_flip(['name', 'description', 'contact_phone'])));
            $fields = array_keys($club->getDirty());
            $club->save();
            if ($fields !== []) {
                PlatformActivity::record($actor, 'club.profile_updated', $club, $club, ['fields' => $fields]);
            }
        });
    }

    public function changeStatus(User $actor, Club $club, string $status, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $club, $status, $reason): void {
            $club = Club::whereKey($club->id)->lockForUpdate()->firstOrFail();
            $previous = $club->status;
            if ($previous === $status) {
                return;
            }
            $club->status = $status;
            if ($status === 'suspended') {
                $club->suspension_reason = $reason;
                $club->suspended_by = $actor->id;
                $club->suspended_at = now();
            }
            $club->save();
            PlatformActivity::record($actor, match ($status) {
                'active' => 'club.activated','suspended' => 'club.suspended',default => 'club.deactivated'
            }, $club, $club, ['previous_status' => $previous, 'status' => $status]);
        });
    }
}
