@extends('layouts.app')
@section('page-title', 'Club activity')
@section('content')<p class="eyebrow">{{ $club->name }}</p><h1>Club administration history</h1><p class="muted">Activities for this club only. Times shown in Mauritius (UTC+4).</p><section class="card">@include('admin.partials.activities')<div class="pagination">{{ $activities->links() }}</div></section>@endsection
