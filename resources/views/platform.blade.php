@extends('layouts.app')
@section('content')<p class="eyebrow">SYSTEM ADMINISTRATOR</p>
<h1>Platform administration</h1>
<p class="muted">Create and manage clubs. Club membership remains separate from platform access.</p>
<section class="card">
<h2>Create a club</h2>
<form method="post" action="{{ route('platform.clubs.store') }}">@csrf<label for="name">Club name</label>
<input id="name" name="name" value="{{ old('name') }}" required maxlength="150">
<label for="admin-phone">Initial administrator's phone</label>
<input id="admin-phone" name="administrator_phone" type="tel" required placeholder="+230?">
<small>The account must already exist. Assignment does not activate membership.</small>
<button>Create club</button>
</form>
</section>
<h2>Clubs</h2>
@forelse($clubs as $item)<section class="card">
<h2>{{ $item->name }}</h2>
<span class="badge">{{ $item->is_active?'Active':'Inactive' }}</span>
<form method="post" action="{{ route('platform.clubs.update',$item) }}">@csrf @method('PATCH')<label for="name-{{ $item->id }}">Club name</label>
<input id="name-{{ $item->id }}" name="name" value="{{ $item->name }}" required maxlength="150">
<input type="hidden" name="is_active" value="0">
<label class="check">
<input type="checkbox" name="is_active" value="1" @checked($item->is_active)> Club active</label>
<button>Save changes</button>
</form>
<p>Private joining link</p>
<input readonly aria-label="Private joining link for {{ $item->name }}" value="{{ route('join',['code'=>$item->join_code]) }}">
<form method="post" action="{{ route('platform.clubs.assign',$item) }}">@csrf<label for="assign-{{ $item->id }}">Assign Club Administrator by phone</label>
<input id="assign-{{ $item->id }}" name="administrator_phone" type="tel" required>
<button>Assign role</button>
</form>
<p class="muted">Administrators: {{ $item->memberships->filter(fn($m)=>in_array('Club Administrator',$m->roles,true))->map(fn($m)=>$m->user->name.' ('.$m->status.')')->join(', ') ?: 'None assigned' }}</p>
</section>@empty<div class="card empty">No clubs created yet.</div>@endforelse @endsection
