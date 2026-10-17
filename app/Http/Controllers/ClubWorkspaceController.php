<?php

namespace App\Http\Controllers;

use App\Models\Club;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ClubWorkspaceController extends Controller
{
    public function dashboard(Club $club): View
    {
        Gate::authorize('administer', $club);
        $members = $club->memberships();
        $statistics = ['total' => $members->count(), 'active' => $club->memberships()->where('status', 'active')->count(), 'pending' => $club->memberships()->where('status', 'pending')->where('admission_status', 'approved')->count(), 'requests' => $club->joinRequests()->where('status', 'pending')->count()];
        $leadership = $club->memberships()->whereIn('status', ['active', 'pending'])->where(fn ($q) => $q->whereJsonContains('roles', 'Club Administrator')->orWhereJsonContains('roles', 'President')->orWhereJsonContains('roles', 'Accountant')->orWhereJsonContains('roles', 'Committee Member'))->with('user')->get();
        $setup = ['Club description' => filled($club->description), 'Contact information' => filled($club->contact_phone) || filled($club->contact_email), 'President assigned' => $leadership->contains(fn ($m) => in_array('President', $m->roles, true)), 'Accountant assigned' => $leadership->contains(fn ($m) => in_array('Accountant', $m->roles, true)), 'Active payment destination' => $club->paymentDestinations()->where('is_active', true)->exists(), 'Approved policy version' => $club->policyVersions()->exists()];

        return view('clubs.dashboard', ['club' => $club, 'statistics' => $statistics, 'leadership' => $leadership, 'setup' => $setup, 'activities' => $club->activities()->with('actor')->latest()->orderByDesc('id')->limit(8)->get()]);
    }

    public function activities(Club $club): View
    {
        Gate::authorize('administer', $club);

        return view('clubs.activities', ['club' => $club, 'activities' => $club->activities()->with('actor')->latest()->orderByDesc('id')->paginate(20)]);
    }
}
