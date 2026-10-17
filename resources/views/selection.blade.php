@extends('layouts.app')
@section('content')<p class="eyebrow">YOUR WORKSPACE</p>
<h1>{{ $clubs->isEmpty() ? 'Your membership journey' : 'Choose your club' }}</h1>
<p class="muted">Only clubs you are authorised to access appear here.</p>
<div class="grid">@foreach($clubs as $item)<a class="card club-card" href="{{ route(auth()->user()->clubLandingRoute($item),$item) }}">
<span class="badge">Authorised workspace</span>
<h2>{{ $item->name }}</h2>
<span>Open club workspace &rarr;</span>
</a>@endforeach</div>
@if($clubs->isEmpty())<div class="card">
<h2>No active club access yet</h2>
<p>Use your club's joining code or invitation to request membership.</p>
<a class="button" href="{{ route('join') }}">Join a club</a>
</div>@endif
@foreach($memberships->where('status','!=','active') as $item)<div class="card">
<h2>{{ $item->club->name }}</h2>
<span class="badge">{{ ucfirst($item->status) }}</span>
<p class="muted">@if($item->status==='pending')Awaiting first complete share contribution verification. Financial verification is not available yet.@else Contact your club administrator about your membership status. @endif</p>
</div>@endforeach
@foreach($invitations as $item)<div class="card">
<h2>Invitation: {{ $item->club->name }}</h2>
<p class="muted">Expires {{ $item->expires_at->format('d M Y') }}. Open the private invitation link sent by your administrator to accept.</p>
</div>@endforeach
@endsection
