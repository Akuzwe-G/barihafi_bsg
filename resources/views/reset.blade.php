@extends('layouts.app')
@section('content')<div class="auth-card">
<h1>Choose a new password</h1>
<form method="post" action="{{ route('password.update') }}">@csrf<input type="hidden" name="token" value="{{ $token }}">
<input type="hidden" name="phone" value="{{ $phone }}">
<label for="password">New password</label>
<input id="password" name="password" type="password" autocomplete="new-password" required minlength="12">
<small>At least 12 characters.</small>
<label for="confirmation">Confirm password</label>
<input id="confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="12">
<button class="wide">Update password</button>
</form>
</div>@endsection
