<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="referrer" content="no-referrer">
    <title>@yield('page-title', 'Workspace') | {{ $branding->platform_name }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="--brand-accent: {{ $branding->brand_color }}" x-data="{ menu: false, confirming: false, pendingForm: null, confirmMessage: '' }" @keydown.escape.window="menu = false; confirming = false">
<a class="skip" href="#content">Skip to content</a>
@php($platformUser = auth()->check() && auth()->user()->is_system_admin)
<div class="shell">
    <button x-show="menu" x-cloak class="nav-backdrop" @click="menu = false" aria-label="Close navigation"></button>
    <aside id="main-navigation" :class="{ 'open': menu }">
        <a class="brand" href="{{ $platformUser ? route('admin.dashboard') : route('dashboard') }}"><span class="mark">{{ $branding->brand_initials }}</span><span>{{ $branding->platform_name }}</span></a>
        <p class="eyebrow">{{ $platformUser ? 'PLATFORM ADMINISTRATION' : 'SAVING CLUB' }}</p>
        <nav aria-label="Main navigation">
        @auth
            @if(auth()->user()->must_change_password)
                <a href="{{ route('password.change') }}" class="current">Secure your account</a>
            @elseif($platformUser)
                @foreach(['admin.dashboard' => 'Dashboard', 'admin.clubs.index' => 'Clubs', 'admin.users.index' => 'Users', 'admin.activities' => 'Activity Logs', 'admin.settings' => 'Platform Settings', 'admin.profile' => 'My Profile'] as $destination => $label)
                    <a href="{{ route($destination) }}" @class(['current' => request()->routeIs($destination) || ($destination === 'admin.clubs.index' && request()->routeIs('admin.clubs.*')) || ($destination === 'admin.users.index' && request()->routeIs('admin.users.*'))]) @if(request()->routeIs($destination)) aria-current="page" @endif><span class="nav-dot" aria-hidden="true"></span>{{ $label }}</a>
                @endforeach
            @else
                <a href="{{ route('dashboard') }}">My workspace</a>
                @if(isset($club))
                    @can('view', $club)<a href="{{ route('clubs.show', $club) }}">Club overview</a>@endcan
                    @can('onboard', $club)<a href="{{ route('clubs.onboarding', $club) }}">Administrator onboarding</a>@endcan
                    @can('manage', $club)<a href="{{ route('clubs.members', $club) }}">Membership management</a>@endcan
                @endif
                <a href="{{ route('join') }}">Join a club</a>
            @endif
        @else
            <a href="{{ route('login') }}">Sign in</a><a href="{{ route('join') }}">Join by invitation or code</a>
        @endauth
        </nav>
        <div class="sidebar-note"><span class="status-dot"></span> Secure workspace<br>Built on trust. Managed with care.</div>
    </aside>
    <div class="workspace">
        <header>
            <div class="header-title"><button class="mobile-toggle secondary" @click="menu = !menu" :aria-expanded="menu" aria-controls="main-navigation">Menu</button><span class="context">@yield('page-title', 'Barihafi workspace')</span></div>
            @auth
            <div class="header-actions">
                @if(!$platformUser && auth()->user()->accessibleClubs()->count() > 1)<a href="{{ route('dashboard') }}">Switch club</a>@endif
                @if($platformUser)
                <div class="profile" x-data="{ notifications: false }" @keydown.escape.window="notifications = false">
                    <button class="icon-button secondary" @click="notifications = !notifications" :aria-expanded="notifications" aria-label="Notifications"><svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg></button>
                    <div class="profile-menu" x-show="notifications" x-cloak @click.outside="notifications = false"><strong>Notifications</strong><p class="muted">Notifications are not available in this phase.</p></div>
                </div>
                @endif
                <div class="profile" x-data="{ profile: false }" @keydown.escape.window="profile = false">
                    <button class="profile-trigger secondary" @click="profile = !profile" :aria-expanded="profile"><span class="avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</span><span>{{ auth()->user()->name }}</span><span aria-hidden="true">&#9662;</span></button>
                    <div class="profile-menu" x-show="profile" x-cloak @click.outside="profile = false">
                        <p class="muted">{{ auth()->user()->phone }}</p>
                        @if($platformUser)<a href="{{ route('admin.profile') }}">My profile</a>@endif
                        <a href="{{ route('password.change') }}">Change password</a>
                        <form method="post" action="{{ route('logout') }}">@csrf<button type="submit" class="wide secondary">Sign out</button></form>
                    </div>
                </div>
            </div>
            @endauth
        </header>
        <main id="content" tabindex="-1">
            @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="error" role="alert"><strong>Please check the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @if(session('setup_url'))<div class="notice"><label for="setup-url">Private account setup link - valid for 48 hours</label><input id="setup-url" readonly value="{{ session('setup_url') }}" @focus="$el.select()"><small>Deliver privately to the verified account holder. This link will not be shown again; you can issue a replacement from their user details.</small></div>@endif
            @yield('content')
        </main>
        <footer><span>{{ $branding->platform_name }} &middot; Secure club administration</span><span>Display currency {{ $branding->currency }}</span></footer>
    </div>
</div>
<div class="modal-backdrop" x-show="confirming" x-cloak>
    <section class="confirmation-dialog" role="dialog" aria-modal="true" aria-labelledby="confirmation-title" @keydown.tab.prevent="$event.target === $refs.cancelConfirmation ? $refs.confirmAction.focus() : $refs.cancelConfirmation.focus()">
        <p class="eyebrow">CONFIRM ADMINISTRATIVE ACTION</p><h2 id="confirmation-title">Review this change</h2><p x-text="confirmMessage"></p>
        <div class="actions"><button class="secondary" x-ref="cancelConfirmation" @click="confirming = false; pendingForm.querySelector('button[type=submit]').focus()">Cancel</button><button x-ref="confirmAction" @click="confirming = false; pendingForm.querySelector('button[type=submit]').disabled = true; pendingForm.querySelector('button[type=submit]').textContent = 'Working...'; pendingForm.submit()">Confirm change</button></div>
    </section>
</div>
</body>
</html>
