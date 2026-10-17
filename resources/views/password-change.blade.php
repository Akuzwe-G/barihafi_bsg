@extends('layouts.app')
@section('page-title', 'Change password')
@section('content')
<div class="auth-card">
    <p class="eyebrow">SECURE YOUR ACCOUNT</p>
    <h1>{{ auth()->user()->must_change_password ? 'Change your initial password' : 'Change your password' }}</h1>
    <p class="muted">{{ auth()->user()->must_change_password ? 'Choose a private password before accessing your workspace.' : 'Enter your current password and choose a different strong password.' }}</p>
    <form method="post" action="{{ route('password.change.update') }}" x-data="{ submitting: false }" @submit="submitting = true" :aria-busy="submitting">
        @csrf
        <label for="current-password">Current password</label>
        <input id="current-password" type="password" name="current_password" autocomplete="current-password" required>
        <label for="password">New password</label>
        <input id="password" type="password" name="password" autocomplete="new-password" minlength="16" maxlength="72" aria-describedby="password-help" required>
        <small id="password-help">16-72 characters, including uppercase, lowercase, a number and a symbol. Must differ from your current password.</small>
        <label for="confirmation">Confirm new password</label>
        <input id="confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="16" maxlength="72" required>
        <button class="wide" :disabled="submitting" x-text="submitting ? 'Updating...' : 'Change password'">Change password</button>
    </form>
</div>
@endsection
