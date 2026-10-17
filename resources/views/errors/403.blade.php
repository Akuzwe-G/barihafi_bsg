@extends('layouts.app')
@section('content')<div class="card"><p class="eyebrow">ACCESS DENIED</p><h1>This workspace is unavailable</h1><p>You do not have permission to open this page. Your membership may be pending, suspended or revoked, or the club may be inactive.</p><a href="{{ route('dashboard') }}">Return to your workspace</a></div>@endsection
