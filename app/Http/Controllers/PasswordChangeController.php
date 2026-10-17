<?php

namespace App\Http\Controllers;

use App\Models\PlatformActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordChangeController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', 'max:72', Password::min(16)->mixedCase()->numbers()->symbols()],
        ]);
        if (strlen($data['password']) > 72 || str_contains($data['password'], "\0")) {
            throw ValidationException::withMessages(['password' => 'The password must be at most 72 bytes and contain no null characters.']);
        }
        $user = $request->user();
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a different password from your current password.']);
        }
        DB::transaction(function () use ($user, $data): void {
            $user->password = $data['password'];
            $user->must_change_password = false;
            $user->remember_token = Str::random(60);
            $user->save();
            DB::table('password_reset_tokens')->where('email', $user->phone)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            PlatformActivity::record($user, 'account.password_changed', $user);
        });
        $request->session()->regenerate();
        $request->session()->put('password_hash_web', $user->getAuthPassword());

        return redirect()->route($user->is_system_admin ? 'admin.dashboard' : 'dashboard')->with('status', 'Password changed. Your account is ready.');
    }
}
