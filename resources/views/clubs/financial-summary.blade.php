@can('financial_settings.view',$club)
<section class="card"><div class="section-heading"><h2>Financial governance</h2><a href="{{ route('clubs.financial.index',$club) }}">Financial settings &rarr;</a></div>
@can('financial_settings.propose',$club)<div class="role-list">@foreach(['draft','submitted','rejected','scheduled'] as $state)<span class="badge">{{ ucfirst($state) }}: {{ $club->ruleProposals()->where('status',$state)->count() }}</span>@endforeach</div>@endcan
@can('financial_settings.approve',$club)<h2>Outstanding independent reviews</h2>@forelse($club->ruleProposals()->whereIn('status',['submitted','pending'])->where('proposed_by','!=',auth()->id())->latest()->get() as $review)<p><a href="{{ route('clubs.financial.proposals.show',[$club,$review]) }}">Proposal #{{ $review->id }} &middot; {{ \Illuminate\Support\Str::limit($review->rationale,90) }}</a></p>@empty<p class="muted">No financial-rule proposals awaiting your review.</p>@endforelse<p><a href="{{ route('clubs.financial.page',[$club,'committee']) }}">Review committee nominations</a></p>@endcan
</section>
@endcan
