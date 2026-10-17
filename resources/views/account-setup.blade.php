@extends('layouts.app')
@section('page-title', 'Set up your account')
@section('content')
<div class="auth-card"><p class="eyebrow">PRIVATE ACCOUNT INVITATION</p><h1>Choose your password</h1><p class="muted">Create a private password to complete account setup. Your club financial membership remains pending.</p>
<form method="post" action="{{ route('account.setup.store', $token) }}" x-data="{ submitting: false }" @submit="submitting = true">@csrf<label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="new-password" minlength="16" maxlength="72" aria-describedby="password-help"><small id="password-help">16-72 characters, including uppercase, lowercase, a number and a symbol. Maximum 72 bytes.</small><label for="confirmation">Confirm password</label><input id="confirmation" name="password_confirmation" type="password" required minlength="16" maxlength="72" autocomplete="new-password"><button class="wide" type="submit" :disabled="submitting" x-text="submitting ? 'Completing setup...' : 'Complete account setup'">Complete account setup</button></form></div>
@endsection
