<div class="activity-list">
@forelse($activities as $activity)
    <article class="activity-row"><span class="activity-marker" aria-hidden="true"></span><div><strong>{{ ucwords(str_replace(['.', '_'], ' ', $activity->action)) }}</strong><p class="muted">{{ $activity->actor?->name ?? 'System' }} &middot; {{ $activity->subject_type }} #{{ $activity->subject_id }}@if($activity->club) &middot; {{ $activity->club->name }}@endif</p>@if($activity->changes)<details><summary>View recorded changes</summary><pre>{{ json_encode($activity->changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details>@endif</div><time datetime="{{ $activity->created_at->toIso8601String() }}">{{ $activity->created_at->timezone('Indian/Mauritius')->format('d M Y, H:i') }}</time></article>
@empty
    <div class="empty">No platform activity recorded yet.</div>
@endforelse
</div>
