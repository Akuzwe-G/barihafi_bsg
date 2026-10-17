<?php

namespace App\Policies;

use App\Models\Club;
use App\Models\User;

class ClubPolicy
{
    public function view(User $user, Club $club): bool
    {
        return $user->account_status === 'active' && $club->is_active && $club->memberships()->where('user_id', $user->id)->where('status', 'active')->exists();
    }

    public function manage(User $user, Club $club): bool
    {
        return $this->view($user, $club) && in_array('Club Administrator', $club->memberships()->where('user_id', $user->id)->first()->roles, true);
    }

    public function onboard(User $user, Club $club): bool
    {
        return $user->account_status === 'active' && $club->is_active && $club->memberships()->where('user_id', $user->id)->where('status', 'pending')->where('has_onboarding_access', true)->whereJsonContains('roles', 'Club Administrator')->exists();
    }

    public function administer(User $user, Club $club): bool
    {
        return $this->manage($user, $club) || $this->onboard($user, $club);
    }
}
