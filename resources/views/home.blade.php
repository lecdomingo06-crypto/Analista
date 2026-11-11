@extends('layouts.app')
@section('title', 'A little guidance. A better thesis.')
@section('body-class', 'home-page')
@section('content')
<div class="home-content">
<div class="home-hero">
<div class="home-sky" aria-hidden="true">
    @foreach([
        [70, 150, 70, -10.5, .95], [190, 90, 95, -52.3, .8],
        [330, 220, 60, -49.2, .9], [470, 70, 110, -33, .7],
        [600, 120, 80, -54.4, .85], [760, 180, 66, -3.3, .9],
        [880, 60, 120, -108, .65], [980, 100, 88, -37, .8],
    ] as [$top, $width, $duration, $delay, $opacity])
        <svg class="home-sky-cloud" viewBox="0 0 10 6" shape-rendering="crispEdges" focusable="false" style="--cloud-top:{{ $top }}px;--cloud-width:{{ $width }}px;--cloud-duration:{{ $duration }}s;--cloud-delay:{{ $delay }}s;--cloud-opacity:{{ $opacity }}"><rect x="2" y="0" width="3" height="1"/><rect x="6" y="0" width="2" height="1"/><rect x="1" y="1" width="8" height="1"/><rect x="0" y="2" width="10" height="3"/><rect x="1" y="5" width="8" height="1"/></svg>
    @endforeach
</div>
<div class="home-hero-inner">
    <section class="home-intro" aria-labelledby="home-title">
        <div class="home-intro-copy">
            <span class="home-intro-badge"><x-icon name="heart"/>Free &amp; open to the community</span>
            <h1 id="home-title">A little guidance.<br>A better <em>thesis.</em></h1>
            <p>Connect with volunteer IT professionals for thoughtful feedback on your thesis. Share your draft, work through recommendations, and make your next chapter stronger.</p>
            <div class="home-intro-links">
                <a class="btn btn-primary" href="{{ route('evaluators.index') }}"><x-icon name="users"/>Find an evaluator</a>
                <a class="btn" href="{{ route('projects.index') }}">Explore projects<x-icon name="arrow-up-right"/></a>
            </div>
            <ul class="home-intro-meta" aria-label="What the community offers"><li>Volunteer expertise</li><li>Practical feedback</li><li>Free for students</li></ul>
        </div>
        <div class="home-intro-art">
            @include('home.partials.mascot')
        </div>
    </section>
</div>
</div>
<div class="home-sections">
    <section class="home-section" aria-labelledby="home-guides-title">
        <div class="home-section-heading"><h2 id="home-guides-title">Videos for a better thesis</h2><a href="{{ route('tutorials.index') }}">All videos <x-icon name="arrow-up-right"/></a></div>
        <div class="home-video-cards">
            @forelse($tutorials as $tutorial)
                <a class="home-video-card" href="{{ route('tutorials.show', $tutorial) }}">
                    <div class="home-video-thumbnail"><x-tutorial-thumbnail :tutorial="$tutorial"/><span aria-hidden="true"><x-icon name="play"/></span></div>
                    <div class="home-video-copy">
                        <span class="home-video-topic">{{ $tutorial->category }}</span>
                        <h3>{{ $tutorial->title }}</h3>
                        <p>{{ $tutorial->excerpt }}</p>
                        <div class="home-video-footer"><span class="home-video-creator">{{ $tutorial->video_creator }}</span><span class="home-video-watch">Watch video <x-icon name="arrow-up-right"/></span></div>
                    </div>
                </a>
            @empty
                <div class="home-empty"><p>New video tutorials will appear here when published.</p></div>
            @endforelse
        </div>
    </section>

    <section class="home-section" aria-labelledby="home-people-title">
        <div class="home-section-heading"><h2 id="home-people-title">People who can help</h2><a href="{{ route('evaluators.index') }}">All evaluators <x-icon name="arrow-up-right"/></a></div>
        <div class="home-evaluator-deck" data-evaluator-deck>
            @forelse($reviewers as $evaluator)
                <x-reviewer-card :evaluator="$evaluator" :featured="true"/>
            @empty
                <div class="home-empty"><p>Our community is growing. Volunteer IT professionals appear here after admin approval.</p>@guest<a href="{{ route('register', ['role' => 'evaluator']) }}">Become an evaluator ↗</a>@else @if(auth()->user()->role === 'evaluator')<a href="{{ route('account.evaluator-verification.show') }}">Complete your application ↗</a>@elseif(auth()->user()->isAdmin())<a href="{{ route('admin.index', ['section' => 'evaluators']) }}">Review applications ↗</a>@endif @endguest</div>
            @endforelse
        </div>
        @if($reviewers->count() > 1)<p class="home-evaluator-help" data-evaluator-help hidden>Choose a card to meet another evaluator.</p><p class="sr-only" data-evaluator-announcement aria-live="polite" aria-atomic="true"></p>@endif
    </section>

    <section class="home-section" aria-labelledby="home-workflow-title">
        <div class="home-section-heading"><h2 id="home-workflow-title">A simple way forward</h2><span class="home-free">100% free</span></div>
        <ol class="home-workflow" role="list">
            <li class="home-workflow-card">
                <div class="home-workflow-heading"><x-icon name="upload" class="home-workflow-symbol"/><div><span class="home-workflow-phase">01 / Project submission</span><h3>Share your work.</h3></div></div>
                <p>Create a project, upload your thesis, and tell us where you need feedback. Start requesting reviews as soon as you submit.</p>
            </li>
            <li class="home-workflow-card">
                <div class="home-workflow-heading"><x-icon name="users" class="home-workflow-symbol"/><div><span class="home-workflow-phase">02 / Evaluator review</span><h3>Find a fresh perspective.</h3></div></div>
                <p>Connect with an evaluator who knows your topic. Request a review and agree on a timeline.</p>
            </li>
            <li class="home-workflow-card">
                <div class="home-workflow-heading"><x-icon name="check-check" class="home-workflow-symbol"/><div><span class="home-workflow-phase">03 / Revisions &amp; progress</span><h3>Make your next draft stronger.</h3></div></div>
                <p>Work through practical recommendations, ask questions, and keep your revisions together.</p>
            </li>
        </ol>
    </section>

    @if(!auth()->check() || auth()->user()->role === 'evaluator')
        <section class="home-give-back" aria-labelledby="home-community-cta-title">
            <svg class="home-cta-cloud" viewBox="0 0 10 6" aria-hidden="true" focusable="false"><rect x="2" y="0" width="3" height="1"/><rect x="6" y="0" width="2" height="1"/><rect x="1" y="1" width="8" height="1"/><rect x="0" y="2" width="10" height="3"/><rect x="1" y="5" width="8" height="1"/></svg>
            <div class="home-cta-body">
                @include('home.partials.community-mascot')
                <h2 id="home-community-cta-title">A little expertise goes a <em>long way.</em></h2>
                <p>Share what you know. Help a student take the next step.</p>
                <div class="home-cta-actions">
                    <a class="btn btn-primary" href="{{ auth()->check() ? route('account.evaluator-verification.show') : route('register', ['role' => 'evaluator']) }}"><x-icon name="heart"/>{{ auth()->check() ? 'Manage verification' : 'Share your expertise' }}</a>
                    <a class="btn" href="{{ route('evaluators.index') }}">Meet the evaluators <x-icon name="arrow-up-right"/></a>
                </div>
            </div>
        </section>
    @endif
</div>
</div>
@include('home.partials.tour')
@endsection
@push('styles')<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>@endpush
@push('styles')<link rel="stylesheet" href="{{ asset('assets/evaluator-cards.css') }}?v={{ filemtime(public_path('assets/evaluator-cards.css')) }}">@endpush
@push('styles')<link rel="stylesheet" href="{{ asset('assets/home.css') }}?v={{ filemtime(public_path('assets/home.css')) }}">@endpush
@push('styles')<link rel="stylesheet" href="{{ asset('assets/mascot.css') }}?v={{ filemtime(public_path('assets/mascot.css')) }}">@endpush
@push('scripts')<script src="{{ asset('assets/home-evaluators.js') }}?v={{ filemtime(public_path('assets/home-evaluators.js')) }}" defer></script>@endpush
@push('styles')<link rel="stylesheet" href="{{ asset('assets/home-tour.css') }}?v={{ filemtime(public_path('assets/home-tour.css')) }}">@endpush
@push('scripts')<script src="{{ asset('assets/home-tour.js') }}?v={{ filemtime(public_path('assets/home-tour.js')) }}" defer></script>@endpush
