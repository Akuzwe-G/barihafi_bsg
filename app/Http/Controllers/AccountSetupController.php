<?php

namespace App\Http\Controllers;

use App\Models\AccountSetupInvitation;
use App\Models\PlatformActivity;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountSetupController extends Controller
{
    public function show(string $token): View
    {
        abort_unless(AccountSetupInvitation::where('token_hash', hash('sha256', $token))->whereNull('used_at')->where('expires_at', '>', now())->whereHas('user', fn ($query) => $query->where('account_status', 'invited'))->exists(), 404);

        return view('account-setup', ['token' => $token]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'confirmed', 'max:72', Password::min(16)->mixedCase()->numbers()->symbols()]]);
        if (strlen($data['password']) > 72 || str_contains($data['password'], "\0")) {
            throw ValidationException::withMessages(['password' => 'Use a password of at most 72 bytes without null characters.']);
        }
        DB::transaction(function () use ($data, $token): void {
            $invitation = AccountSetupInvitation::where('token_hash', hash('sha256', $token))->whereNull('used_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            if (! $invitation) {
                throw ValidationException::withMessages(['password' => 'This account setup link is unavailable or expired.']);
            }
            $user = User::whereKey($invitation->user_id)->lockForUpdate()->firstOrFail();
            if ($user->account_status !== 'invited') {
                throw ValidationException::withMessages(['password' => 'This account setup link is unavailable or expired.']);
            }
            $user->password = Hash::make($data['password']);
            $user->account_status = 'active';
            $user->must_change_password = false;
            $user->save();
            $invitation->update(['used_at' => now()]);
            AccountSetupInvitation::where('user_id', $user->id)->whereNull('used_at')->update(['expires_at' => now()]);
            PlatformActivity::record($user, 'account.setup_completed', $user);
        });

        return redirect()->route('login')->with('status','Account setup complete. Sign in with your phone or email. Club financial membership remains pending.');
    }
}
