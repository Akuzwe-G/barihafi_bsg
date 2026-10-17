@extends('layouts.app')
@section('content')<div class="auth-card">
<p class="eyebrow">ACCOUNT SUPPORT</p>
<h1>Recover your account</h1>
<p>Contact your club administrator through your established private contact channel. They will arrange identity verification with the platform operator.</p>
<p class="muted">After verification, the operator can privately issue a single-use recovery link valid for one hour. This works with or without an email address.</p>
<a href="{{ route('login') }}">Return to sign in</a>
</div>@endsection
