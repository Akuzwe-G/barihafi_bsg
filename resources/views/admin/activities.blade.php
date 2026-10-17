@extends('layouts.app')
@section('page-title', 'Activity Logs')
@section('content')
<div class="page-heading"><div><p class="eyebrow">PLATFORM AUDIT TRAIL</p><h1>Activity logs</h1><p class="muted">Administrative actions and relevant security changes. Times shown in Mauritius (UTC+4).</p></div></div>
<section class="card"><form method="get" action="{{ route('admin.activities') }}" class="filter-bar"><div><label for="action">Action</label><select id="action" name="action"><option value="">All actions</option>@foreach($actions as $action)<option value="{{ $action }}" @selected(request('action') === $action)>{{ ucwords(str_replace(['.', '_'], ' ', $action)) }}</option>@endforeach</select></div><div><label for="from">From date (UTC)</label><input id="from" name="from" type="date" value="{{ request('from') }}"></div><div><label for="to">To date (UTC)</label><input id="to" name="to" type="date" value="{{ request('to') }}"></div><button type="submit">Apply filters</button><a href="{{ route('admin.activities') }}">Clear</a></form>@include('admin.partials.activities')<div class="pagination">{{ $activities->links() }}</div></section>
@endsection
