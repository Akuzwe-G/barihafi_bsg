<?php

namespace App\Http\Controllers;

use App\Models\PlatformActivity;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class RecoveryController extends Controller
{
    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate(['phone' => 'required|string|max:40', 'token' => 'required|string|max:128', 'password' => ['required', 'confirmed', Password::min(12)]]);
        $phone = Phone::normalize($data['phone']);
        DB::transaction(function () use ($data, $phone) {
            $record = DB::table('password_reset_tokens')->where('email', $phone)->lockForUpdate()->first();
            $user = User::where('phone', $phone)->first();
            if (! $record || ! $user || Carbon::parse($record->created_at)->addHour()->isPast() || ! Hash::check($data['token'], $record->token)) {
                throw ValidationException::withMessages(['token' => 'This recovery link is invalid or expired.']);
            }
            $user->password = $data['password'];
            $user->remember_token = Str::random(60);
            $user->save();
            DB::table('password_reset_tokens')->where('email', $phone)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            PlatformActivity::record($user, 'account.password_recovered', $user);
        });
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Password updated. Sign in with your new password.');
    }
}
