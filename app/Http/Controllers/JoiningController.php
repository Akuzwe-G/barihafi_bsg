<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\ClubInvitation;
use App\Models\PlatformActivity;
use App\Models\User;
use App\Support\ClubMembershipWorkflow;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class JoiningController extends Controller
{
    public function show(Request $request): View
    {
        $request->validate(['code' => ['nullable', 'string', 'max:64'], 'invitation' => ['nullable', 'string', 'max:128']]);

        return view('join', ['code' => $request->query('code', ''), 'invitation' => $request->query('invitation', '')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:64'], 'invitation' => ['nullable', 'string', 'max:128']]);
        $account = [];
        if (! $request->user()) {
            $request->validate(['phone' => ['required', 'string', 'max:40'], 'email' => ['nullable', 'string', 'max:255']]);
            $request->merge(['phone' => Phone::normalize((string) $request->input('phone')), 'email' => $request->filled('email') ? strtolower(trim($request->input('email'))) : null]);
            $account = $request->validate(['name' => ['required', 'string', 'max:150'], 'phone' => ['required', 'unique:users,phone'], 'email' => ['nullable', 'email', 'max:255', 'unique:users,email'], 'password' => ['required', 'confirmed', Password::min(12)]]);
        }
        $user = DB::transaction(function () use ($request, $data, $account) {
            $invitation = null;
            if (! empty($data['invitation'])) {
                $invitation = ClubInvitation::where('token_hash', hash('sha256', $data['invitation']))->whereNull('accepted_at')->where('expires_at', '>', now())->lockForUpdate()->first();
                $club = $invitation ? Club::whereKey($invitation->club_id)->where('is_active', true)->first() : null;
            } else {
                $club = Club::where('join_code', $data['code'] ?? '')->where('is_active', true)->first();
            }
            if (! $club) {
                throw ValidationException::withMessages(['code' => 'This joining code or invitation is unavailable.']);
            }
            $user = $request->user();
            $phone = $user?->phone ?? $account['phone'];
            if ($invitation && $invitation->phone !== $phone) {
                throw ValidationException::withMessages(['code' => 'This joining code or invitation is unavailable.']);
            }
            $user ??= User::create($account);
            app(ClubMembershipWorkflow::class)->submit($user, $club);
            if ($invitation) {
                $invitation->update(['accepted_at' => now()]);
                PlatformActivity::record($user, 'club.invitation_accepted', $club, $club);
            }

            return $user;
        });
        if (! $request->user()) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        return redirect()->route('dashboard')->with('status', 'Request recorded. Membership remains pending until the first complete share contribution is verified. Financial verification is not available yet.');
    }

    public function accept(Request $request, ClubInvitation $invitation): RedirectResponse
    {
        DB::transaction(function () use ($request, $invitation): void {
            $invitation = ClubInvitation::whereKey($invitation->id)->where('phone', $request->user()->phone)->whereNull('accepted_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($invitation, 404);
            $club = Club::whereKey($invitation->club_id)->where('is_active', true)->first();
            abort_unless($club, 404);
            app(ClubMembershipWorkflow::class)->submit($request->user(), $club);
            $invitation->update(['accepted_at' => now()]);
            PlatformActivity::record($request->user(), 'club.invitation_accepted', $club, $club);
        });

        return redirect()->route('requests.mine')->with('status', 'Invitation accepted. Admission is awaiting club review; financial membership remains pending.');
    }
}
