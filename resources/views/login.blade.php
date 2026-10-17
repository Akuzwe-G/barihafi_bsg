@extends('layouts.app')
@section('content')<div class="auth-card">
<p class="eyebrow">WELCOME BACK</p>
<h1>Sign in to Barihafi</h1>
<p class="muted">Your clubs, in one secure workspace.</p>
<form method="post" action="{{ route('login') }}">@csrf
<label for="login">Phone number or email</label>
<input id="login" name="login" value="{{ old('login') }}" autocomplete="username" required autofocus aria-describedby="login-help">
<small id="login-help">For phone sign-in, include + and your country code.</small>
<label for="password">Password</label>
<input type="password" id="password" name="password" autocomplete="current-password" required>
<label class="check">
<input type="checkbox" name="remember" value="1" @checked(old('remember'))> Remember me</label>
<button class="wide">Sign in</button>
</form>
<a href="{{ route('password.request') }}">Need to recover your account?</a>
<p class="muted">Have a joining code? <a href="{{ route('join') }}">Create your account</a>
</p>
</div>@endsection
