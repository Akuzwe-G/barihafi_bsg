@extends('layouts.app')
@section('content')<p class="eyebrow">CLUB ADMINISTRATOR</p>
<h1>Membership management</h1>
<p class="muted">{{ $club->name }} ? Membership activation requires financial verification and is unavailable in Phase 1.</p>
@if(session('invitation_url'))<div class="notice">
<label for="invite-url">Private invitation link</label>
<input readonly id="invite-url" value="{{ session('invitation_url') }}">
</div>@endif
<div class="card">
<h2>Invite a member</h2>
<form method="post" action="{{ route('clubs.invite',$club) }}">@csrf<label for="phone">Recipient phone number</label>
<input id="phone" name="phone" type="tel" required placeholder="+230?">
<button>Create private invitation</button>
</form>
<p>Club joining link</p>
<input readonly aria-label="Private joining link" value="{{ route('join',['code'=>$club->join_code]) }}">
</div>
@foreach($memberships as $item)<div class="card">
<h2>{{ $item->user->name }}</h2>
<p class="muted">{{ $item->user->phone }} ? {{ ucfirst($item->status) }}</p>
<form method="post" action="{{ route('clubs.members.update',[$club,$item]) }}">@csrf @method('PATCH')<fieldset>
<legend>Club roles</legend>@foreach(\App\Models\ClubMembership::ROLES as $role)<label class="check">
<input type="checkbox" name="roles[]" value="{{ $role }}" @checked(in_array($role,$item->roles,true))> {{ $role }}</label>@endforeach</fieldset>
<label for="status-{{ $item->id }}">Membership status</label>
<select id="status-{{ $item->id }}" name="status" required>
<option value="unchanged" selected>Keep current status ({{ ucfirst($item->status) }})</option>@foreach(['pending','suspended','revoked'] as $status)<option value="{{ $status }}" >{{ ucfirst($status) }}</option>@endforeach</select>
<button>Update membership</button>
</form>
</div>@endforeach @endsection
