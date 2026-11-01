<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Free, community-powered thesis feedback from volunteer IT professionals. Find your reviewer, improve your next draft, and learn together.">
    <script>
        (() => {
            let preference = 'light';
            try { preference = localStorage.getItem('analista-theme') || 'light'; } catch {}
            if (!['system', 'light', 'dark'].includes(preference)) preference = 'light';
            document.documentElement.dataset.themeMode = preference;
            document.documentElement.dataset.theme = preference === 'system'
                ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : preference;
        })();
    </script>
    <title>@yield('title', 'Your next chapter') — Analista</title>
    <link rel="icon" href="{{ asset('assets/favicon.svg') }}?v={{ filemtime(public_path('assets/favicon.svg')) }}" type="image/svg+xml">
    <link rel="preload" href="{{ asset('assets/fonts/Figtree-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('assets/fonts.css') }}?v={{ filemtime(public_path('assets/fonts.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ filemtime(public_path('assets/app.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/community-chat.css') }}?v={{ filemtime(public_path('assets/community-chat.css')) }}">
    @auth<link rel="stylesheet" href="{{ asset('assets/notifications.css') }}?v={{ filemtime(public_path('assets/notifications.css')) }}">@endauth
    @stack('styles')
    <link rel="stylesheet" href="{{ asset('assets/footer.css') }}?v={{ filemtime(public_path('assets/footer.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/night-theme.css') }}?v={{ filemtime(public_path('assets/night-theme.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/night-mascot.css') }}?v={{ filemtime(public_path('assets/night-mascot.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/toasts.css') }}?v={{ filemtime(public_path('assets/toasts.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/theme-transition.css') }}?v={{ filemtime(public_path('assets/theme-transition.css')) }}">
    <script src="{{ asset('assets/app.js') }}?v={{ filemtime(public_path('assets/app.js')) }}" defer></script>
    <script src="{{ asset('assets/toasts.js') }}?v={{ filemtime(public_path('assets/toasts.js')) }}" defer></script>
    <script src="{{ asset('assets/community-chat.js') }}?v={{ filemtime(public_path('assets/community-chat.js')) }}" defer></script>
    @auth<script src="{{ asset('assets/notifications.js') }}?v={{ filemtime(public_path('assets/notifications.js')) }}" defer></script>@endauth
</head>
<body class="@yield('body-class')" data-auth-user-id="{{ auth()->id() }}" data-session-status-url="{{ route('session.status') }}">
<a class="skip-link" href="#main">Skip to content</a>
<div class="mobile-header"><a class="brand" href="{{ route('home') }}"><x-icon name="mark"/> analista</a><button class="icon-button" data-menu aria-label="Open navigation" aria-expanded="false" aria-controls="sidebar"><x-icon name="menu"/></button></div>
<div class="nav-overlay" data-overlay></div>
<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <a class="brand" href="{{ route('home') }}"><x-icon name="mark"/> analista<span class="brand-dot">.</span></a>
    <span class="side-caption">A little guidance. A better draft.</span>
    <nav aria-label="Primary navigation" tabindex="0">
        @foreach([['home','home','Home'],['evaluators.index','users','Find evaluators'],['projects.index','folder','Thesis projects'],['tutorials.index','book','Tutorials & tips']] as [$route,$icon,$label])
            <a class="nav-link {{ request()->routeIs($route === 'home' ? 'home' : explode('.', $route)[0].'.*') ? 'active' : '' }}" href="{{ route($route) }}" data-tour-link="{{ explode('.', $route)[0] }}"><x-icon :name="$icon"/><span>{{ $label }}</span>@if(request()->routeIs($route))<span class="nav-dot"></span>@endif</a>
        @endforeach
        @auth
        @php($notificationsActive = request()->routeIs('account.dashboard') && request('tab') === 'notifications')
        @php($reviewsActive = request()->routeIs('account.reviews', 'reviews.*') || (request()->routeIs('account.dashboard') && request('tab') === 'reviews'))
        <div class="nav-divider"></div><p class="nav-label">YOUR WORKSPACE</p>
        @if(auth()->user()->role === 'evaluator')<a class="nav-link {{ $reviewsActive ? 'active' : '' }}" href="{{ route('account.reviews') }}" data-tour-link="workspace" @if($reviewsActive) aria-current="page" @endif><x-icon name="inbox"/>My reviews</a>@endif
        @if(auth()->user()->role !== 'evaluator')
        <a class="nav-link {{ request()->routeIs('account.projects', 'account.projects.*', 'projects.create', 'projects.edit') || ($reviewsActive && auth()->user()->role !== 'evaluator') ? 'active' : '' }}" href="{{ route('account.projects') }}" @if(auth()->user()->role !== 'evaluator') data-tour-link="workspace" @endif @if(request()->routeIs('account.projects', 'account.projects.*')) aria-current="page" @endif><x-icon name="folder"/>My projects</a>
        @endif
        <a class="nav-link {{ request()->routeIs('messages.*') ? 'active' : '' }}" href="{{ route('messages.index') }}" data-messages-nav @if(request()->routeIs('messages.*')) aria-current="page" @endif><x-icon name="message-circle"/>Messages @php($unreadMessages = auth()->user()->receivedDirectMessages()->whereNull('read_at')->count())<span class="count" data-message-unread @if(!$unreadMessages) hidden @endif>{{ $unreadMessages }}</span></a>
        <a class="nav-link {{ $notificationsActive ? 'active' : '' }}" href="{{ route('account.dashboard', ['tab'=>'notifications']) }}" data-notifications-open aria-haspopup="dialog" aria-controls="notifications-dialog" aria-expanded="false" @if($notificationsActive) aria-current="page" @endif><x-icon name="bell"/>Notifications @php($unread = auth()->user()->activities()->whereNull('read_at')->count())<span class="count" data-notifications-badge @if(!$unread) hidden @endif>{{ $unread }}</span></a>
        <a class="nav-link {{ request()->routeIs('account.profile*') ? 'active' : '' }}" href="{{ route('account.profile') }}"><x-icon name="user"/>My profile</a>
        @if(auth()->user()->role === 'evaluator')<a class="nav-link {{ request()->routeIs('account.evaluator-verification.*') ? 'active' : '' }}" href="{{ route('account.evaluator-verification.show') }}" data-tour-link="verification"><x-icon name="shield-check"/>Verification</a>@endif
        @if(auth()->user()->isAdmin())<a class="nav-link {{ request()->routeIs('admin.*') ? 'active' : '' }}" href="{{ route('admin.index') }}"><x-icon name="shield"/>Administration</a>@endif
        @endauth
        <div class="nav-divider"></div>
        <a class="nav-link {{ request()->routeIs('guidelines') ? 'active' : '' }}" href="{{ route('guidelines') }}"><x-icon name="heart"/>Community guidelines</a>
        <a class="nav-link {{ request()->routeIs('faq') ? 'active' : '' }}" href="{{ route('faq') }}" @if(request()->routeIs('faq')) aria-current="page" @endif><x-icon name="help-circle"/>FAQ</a>
        @guest<a class="nav-link" href="{{ route('register',['role'=>'evaluator']) }}"><x-icon name="plus"/>Become an evaluator</a>@endguest
    </nav>
    <div @class(['sidebar-bottom', 'sidebar-bottom-auth' => auth()->check()])>
        @include('community-chat.partials.widget')
        <div class="sidebar-utility">
            <div class="sidebar-theme-picker" role="group" aria-label="Color theme">
                <button type="button" data-theme-preference="system" aria-label="Use system theme" aria-pressed="false" title="System theme"><x-icon name="monitor"/></button>
                <button type="button" data-theme-preference="light" aria-label="Use light theme" aria-pressed="true" title="Light theme"><x-icon name="sun"/></button>
                <button type="button" data-theme-preference="dark" aria-label="Use dark theme" aria-pressed="false" title="Dark theme"><x-icon name="moon"/></button>
            </div>
            <a class="sidebar-tour-link" href="{{ route('home') }}#tour" data-tour-start aria-label="Take a tour" title="Take a tour"><x-icon name="compass"/></a>
        </div>
        @auth
        <a class="account-chip" href="{{ route('account.profile') }}"><x-avatar :user="auth()->user()" class="avatar-small" /><span><strong>{{ auth()->user()->name }}</strong><small>{{ ucfirst(auth()->user()->role) }}</small></span><x-icon name="arrow-up-right"/></a>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="text-button" type="submit"><x-icon name="logout"/>Sign out</button></form>
        @else
        <div class="sidebar-guest-panel">
            <div class="sidebar-guest-note">
                <span class="sidebar-guest-icon" aria-hidden="true"><x-icon name="heart"/></span>
                <div><strong>Better drafts, together.</strong><p>Free and open to everyone.</p></div>
            </div>
            <a class="btn btn-primary btn-wide sidebar-login" href="{{ route('login') }}">Log in <x-icon name="arrow-right"/></a>
            <a class="sidebar-register" href="{{ route('register') }}">Join the community <x-icon name="arrow-up-right"/></a>
        </div>
        @endauth
    </div>
</aside>
@php($workspacePage = request()->routeIs('account.*', 'messages.*', 'reviews.*', 'admin.*', 'projects.create', 'projects.edit'))
<div class="app-shell">
    <x-night-sky/>
    <main id="main" @class(['main-content', 'workspace-content' => $workspacePage])>
        @if($errors->any())<div class="alert" role="alert" tabindex="-1" @hasSection('project-validation-title') data-project-errors @endif><strong>@yield('project-validation-title', 'Please check the following:')</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@hasSection('project-validation-help')<p class="small">@yield('project-validation-help')</p>@endif</div>@endif
        @yield('content')
        @unless($workspacePage)
            <footer class="site-footer"><span class="site-footer-copyright">© {{ now()->year }} Analista.</span><nav aria-label="Footer links"><a href="{{ route('faq') }}">FAQ</a><a href="{{ route('guidelines') }}">Community &amp; privacy</a><a href="{{ route('tutorials.index') }}">Keep learning <x-icon name="arrow-up-right"/></a></nav></footer>
        @endunless
    </main>
</div>
@include('partials.toasts')
@include('community-chat.partials.dialog')
@auth @include('notifications.partials.dialog') @endauth
@stack('scripts')
</body>
</html>
