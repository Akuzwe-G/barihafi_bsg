@extends('layouts.app')
@section('page-title','Dashboard')
@section('content')<p class="eyebrow">CLUB OVERVIEW</p>
<h1>{{ $club->name }}</h1>
<p class="muted">Your membership is active. Your roles apply within this club.</p>
<div class="role-list">@foreach(array_unique([...$membership->roles,'Member']) as $role)<span class="badge">{{ $role }}</span>@endforeach</div>
<div class="grid">
@foreach(array_unique([...$membership->roles,'Member']) as $role)<section class="card">
<p class="eyebrow">{{ $role }}</p>
<h2>{{ match($role) { 'Club Administrator'=>'Club administration', 'President'=>'Leadership workspace', 'Accountant'=>'Accounting workspace', 'Committee Member'=>'Committee workspace', default=>'Member workspace' } }}</h2>
<p class="muted">{{ match($role) { 'Club Administrator'=>'Manage club invitations and membership roles.', 'President'=>'Review club financial policy proposals and independent committee nominations.', 'Accountant'=>'Financial reporting will be added in a later phase.', 'Committee Member'=>'Committee activities will be added in a later phase.', default=>'No share transactions recorded yet.' } }}</p>@if($role==='Club Administrator')<a href="{{ route('clubs.members',$club) }}">Manage memberships &rarr;</a>@endif</section>@endforeach</div>
<div class="card"><h2>Club payment instructions</h2><p class="muted">View active destinations configured by your club. No transactions are processed here.</p><a href="{{ route('clubs.payments',$club) }}">View payment instructions</a></div>
<div class="card">
<h2>Financial activity</h2>
<div class="empty">No share transactions recorded yet.<p class="muted">Shares, savings, loans and ledger tools are planned for later phases.</p>
</div>
</div>@include('clubs.financial-summary')
@endsection
