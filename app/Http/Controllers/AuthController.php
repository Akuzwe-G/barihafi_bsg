<?php

namespace App\Http\Controllers;

use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['login' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'max:255'], 'remember' => ['nullable', 'boolean']]);
        $identifier = strtolower(trim($data['login']));
        $email = filter_var($identifier, FILTER_VALIDATE_EMAIL);
        if (! $email) {
            try {
                $identifier = Phone::normalize($identifier);
            } catch (ValidationException $e) {
                $identifier = 'invalid';
            }
        }
        $key = hash('sha256', $identifier.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['login' => 'Too many attempts. Please try again in a minute.']);
        }
        RateLimiter::hit($key, 60);
        if (! Auth::attempt([$email ? 'email' : 'phone' => $identifier, 'password' => $data['password'], 'account_status' => 'active'], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['login' => 'The supplied credentials are invalid.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->route($request->user()->must_change_password ? 'password.change' : 'dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
