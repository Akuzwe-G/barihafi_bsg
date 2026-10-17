@extends('layouts.app')
@section('content')<div class="auth-card">
<p class="eyebrow">MEMBERSHIP</p>
<h1>Join your club</h1>
<p class="muted">Use a private invitation or joining code supplied by your club.</p>
<form method="post" action="{{ route('join.store') }}">@csrf
@if($invitation)<input type="hidden" name="invitation" value="{{ $invitation }}">
<p class="notice">Invitation selected. Use the phone number it was issued to.</p>@else<label for="code">Club joining code</label>
<input id="code" name="code" value="{{ old('code',$code) }}" required autocomplete="off">@endif
@guest
<label for="name">Full name</label>
<input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required maxlength="150">
<label for="phone">Phone number</label>
<input id="phone" name="phone" value="{{ old('phone') }}" type="tel" placeholder="+230 5XXX XXXX" autocomplete="tel" required>
<small>Include + and the country code.</small>
<label for="email">Email (optional)</label>
<input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email">
<label for="password">Password</label>
<input id="password" name="password" type="password" required minlength="12" autocomplete="new-password">
<small>At least 12 characters.</small>
<label for="confirmation">Confirm password</label>
<input id="confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password">
@endguest
<div class="notice">Membership stays pending until your first complete share contribution has been verified. Financial verification is not available in Phase 1.</div>
<button class="wide">@auth Request membership @else Create account & request membership @endauth</button>
</form>@guest<p>Already registered? <a href="{{ route('login') }}">Sign in first</a>
</p>@endguest</div>@endsection
