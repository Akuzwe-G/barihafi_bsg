@extends('layouts.app')
@section('page-title', 'Dashboard')
@section('content')
<div class="page-heading"><div><p class="eyebrow">PLATFORM OVERVIEW</p><h1>System Administrator dashboard</h1><p class="muted">A live view of your clubs, people and administrative activity.</p></div><a class="button" href="{{ route('admin.clubs.create') }}">+ Create club</a></div>
<div class="stats-grid">
    @foreach(['clubs' => 'Total clubs', 'active' => 'Active clubs', 'suspended' => 'Suspended clubs', 'users' => 'Registered users'] as $key => $label)
        <section class="stat-card"><p>{{ $label }}</p><strong>{{ number_format($statistics[$key]) }}</strong><span class="muted">{{ match($key) { 'clubs' => $statistics['inactive'].' inactive', 'active' => 'Available club workspaces', 'suspended' => 'Workspace access blocked', default => 'All registered accounts' } }}</span></section>
    @endforeach
</div>
<section class="card"><div class="section-heading"><div><h2>Club registrations</h2><p class="muted">Actual registrations over the last twelve months, grouped in UTC.</p></div><span class="badge">{{ $chart->sum('count') }} {{ \Illuminate\Support\Str::plural('registration', $chart->sum('count')) }}</span></div>
    @php($maximum = max(1, $chart->max('count')))
    <figure class="registration-chart"><div class="chart-scroll" tabindex="0" aria-label="Monthly registrations; scroll horizontally to explore earlier months" x-init="$el.scrollLeft = $el.scrollWidth"><svg viewBox="0 0 720 230" role="img" aria-labelledby="chart-title chart-description"><title id="chart-title">Monthly club registrations</title><desc id="chart-description">@foreach($chart as $point){{ $point['month'] }}: {{ $point['count'] }} registrations. @endforeach</desc><line x1="10" y1="190" x2="710" y2="190" stroke="#454b57"/>
    @foreach($chart as $point)
        @php($height = $point['count'] / $maximum * 150)
        <g><title>{{ $point['month'] }}: {{ $point['count'] }} registrations</title><rect x="{{ 23 + $loop->index * 58 }}" y="{{ 190 - $height }}" width="34" height="{{ $height }}" rx="4" fill="var(--brand-accent)"/><text x="{{ 40 + $loop->index * 58 }}" y="{{ 180 - $height }}" text-anchor="middle" fill="#edf0f5" font-size="11">{{ $point['count'] }}</text><text x="{{ 40 + $loop->index * 58 }}" y="214" text-anchor="middle" fill="#b0b7c4" font-size="11">{{ $point['label'] }}</text></g>
    @endforeach</svg></div><figcaption class="muted">{{ $chart->first()['month'] }} &ndash; {{ $chart->last()['month'] }}@if($chart->sum('count') === 0) &middot; No club registrations recorded in this period.@endif</figcaption></figure>
</section>
<div class="dashboard-columns"><section class="card"><div class="section-heading"><h2>Recent clubs</h2><a href="{{ route('admin.clubs.index') }}">View all</a></div><div class="table-scroll" tabindex="0" aria-label="Data table; scroll horizontally on small screens"><table><thead><tr><th>Club</th><th>Status</th><th>Memberships</th><th>Registered</th></tr></thead><tbody>@forelse($recentClubs as $item)<tr><td><a href="{{ route('admin.clubs.show', $item) }}">{{ $item->name }}</a><small>{{ $item->code }}</small></td><td><span class="badge status-{{ $item->status }}">{{ ucfirst($item->status) }}</span></td><td>{{ $item->memberships_count }}</td><td>{{ $item->created_at->timezone('Indian/Mauritius')->format('d M Y') }}</td></tr>@empty<tr><td colspan="4" class="empty">No clubs created yet. Create your first club to get started.</td></tr>@endforelse</tbody></table></div></section>
<section class="card"><div class="section-heading"><h2>Recent platform activity</h2><a href="{{ route('admin.activities') }}">View all</a></div>@include('admin.partials.activities')</section></div>
@endsection
