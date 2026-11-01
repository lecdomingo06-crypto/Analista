@extends('layouts.app')
@section('title', $evaluator->name)
@section('crumb', 'evaluator profile')
@section('body-class', 'evaluators-page evaluator-profile-page')
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/evaluator-cards.css') }}?v={{ filemtime(public_path('assets/evaluator-cards.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/evaluators.css') }}?v={{ filemtime(public_path('assets/evaluators.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/evaluator-profile.css') }}?v={{ filemtime(public_path('assets/evaluator-profile.css')) }}">
@endpush
@section('content')
@include('evaluators.partials.sky')
<a class="evaluators-back page-back profile-back" href="{{ route('evaluators.index') }}"><x-icon name="arrow-left"/>Back to evaluators</a>

<div class="profile-layout">
    <div class="profile-main">
        <header class="profile-identity profile-surface">
            <div class="profile-identity-top">
                <x-avatar :user="$evaluator" class="profile-avatar"/>
                <div class="profile-identity-copy">
                    <div class="profile-badges evaluator-card-badges">
                        <x-evaluator-badge :evaluator="$evaluator"/>
                        <span class="profile-availability"><i aria-hidden="true"></i>{{ $evaluator->accepting_requests ? 'Available for reviews' : 'Currently unavailable' }}</span>
                    </div>
                    <h1>{{ $evaluator->name }}</h1>
                    <p class="profile-headline">{{ $evaluator->headline ?: 'Volunteer IT professional sharing thoughtful feedback on your thesis.' }}</p>
                </div>
            </div>
            <div class="profile-facts">
                <span><x-icon name="clock"/>{{ $evaluator->experience_years > 0 ? $evaluator->experience_years.' years experience' : 'Experience not provided' }}</span>
                @if($evaluator->city)<span><x-icon name="map-pin"/>{{ $evaluator->city }}</span>@endif
                <a class="profile-rating-link" href="#community-feedback">
                    <x-icon name="star" :class="$evaluator->ratings_received_count > 0 ? 'is-rated' : ''"/>
                    @if($evaluator->ratings_received_count > 0)
                        <strong>{{ number_format($evaluator->ratings_received_avg_rating, 1) }}<span class="sr-only"> out of 5 stars</span></strong>
                        <span>{{ $evaluator->ratings_received_count }} {{ Str::plural('rating', $evaluator->ratings_received_count) }}</span>
                    @else
                        <span>No ratings yet</span>
                    @endif
                </a>
            </div>
        </header>

        <section class="profile-about profile-surface" aria-labelledby="profile-about-title">
            <h2 id="profile-about-title">A little about me</h2>
            <p class="profile-bio preserve">{{ $evaluator->bio ?: 'This evaluator is ready to share their professional perspective.' }}</p>
            <div class="profile-skill-group">
                <h3>Areas of expertise</h3>
                @if(count($evaluator->expertise ?? []))
                    <div class="profile-tags">@foreach($evaluator->expertise as $area)<span class="profile-tag profile-tag-expertise">{{ $area }}</span>@endforeach</div>
                @else
                    <p class="profile-muted">No areas of expertise listed yet.</p>
                @endif
            </div>
            <div class="profile-skill-group">
                <h3>Skills &amp; technologies</h3>
                @if(count($evaluator->skills ?? []))
                    <div class="profile-tags">@foreach($evaluator->skills as $skill)<span class="profile-tag">{{ $skill }}</span>@endforeach</div>
                @else
                    <p class="profile-muted">No skills listed yet.</p>
                @endif
            </div>
        </section>
    </div>

    <aside class="profile-sidebar" aria-label="Review requests and contact">
        <section class="profile-request profile-surface" aria-labelledby="profile-request-title">
            <p class="profile-label">Request a review</p>
            <h2 id="profile-request-title">{{ $evaluator->accepting_requests ? 'Open to review requests' : 'Currently unavailable' }}</h2>
            <p class="profile-copy">Select your project and the PDF you want reviewed. The evaluator can accept your request and confirm a completion date.</p>
            <dl class="profile-details">
                <div><dt>Review mode</dt><dd>{{ implode(', ', array_map('ucfirst', $evaluator->review_modes ?? ['online'])) }}</dd></div>
                <div><dt>Review capacity</dt><dd>{{ $evaluator->capacity }} at a time</dd></div>
            </dl>
            <div class="profile-request-actions">
                @if($evaluator->accepting_requests)
                    @guest
                        <a class="btn btn-primary btn-wide" href="{{ route('login') }}">Log in to request a review <x-icon name="arrow-right"/></a>
                    @else
                        @if((int) $evaluator->id === (int) auth()->id())
                            <p class="hint">This is your community profile.</p>
                        @elseif($projects->isEmpty())
                            <p class="hint">Create a thesis project and upload your PDF before requesting a review.</p>
                            @if(in_array(auth()->user()->role, ['student', 'admin'], true))
                                <a class="btn btn-primary btn-wide" href="{{ route('projects.create', ['evaluator_id' => $evaluator->id]) }}">Create a thesis project <x-icon name="plus"/></a>
                            @else
                                <a class="btn btn-wide" href="{{ route('account.dashboard') }}">View my reviews</a>
                            @endif
                        @else
                            <form method="GET" action="{{ route('reviews.prepare') }}" class="profile-review-form">
                                <input type="hidden" name="evaluator_id" value="{{ $evaluator->id }}">
                                <label class="field" for="review-project">Choose your project
                                    <select name="project_id" id="review-project" required>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->title }}</option>@endforeach</select>
                                </label>
                                <button class="btn btn-primary btn-wide" type="submit">Request review <x-icon name="arrow-right"/></button>
                                <p class="hint">Next, choose a PDF version and tell the evaluator what you need help with.</p>
                            </form>
                        @endif
                    @endguest
                @endif
                @if(!auth()->check() || (int) auth()->id() !== (int) $evaluator->id)
                    <a class="btn btn-wide profile-message" href="{{ route('messages.compose', $evaluator) }}"><x-icon name="message-circle"/>Message {{ $evaluator->name }}</a>
                @endif
            </div>
            @if($evaluator->portfolio_url)
                <a class="profile-portfolio" href="{{ $evaluator->portfolio_url }}" target="_blank" rel="noopener noreferrer">Portfolio / LinkedIn <x-icon name="arrow-up-right"/></a>
            @endif
            <x-report type="user" :id="$evaluator->id"/>
        </section>
        <section class="profile-gift profile-surface" aria-labelledby="profile-gift-title">
            <span class="profile-gift-icon"><x-icon name="heart"/></span>
            <h2 id="profile-gift-title">Expertise is a gift.</h2>
            <p>Be clear about your questions, respect your evaluator’s time, and use their recommendations to strengthen your own work.</p>
            <p>Reviews are free. After completion, you can rate the guidance and continue talking in Messages.</p>
        </section>
    </aside>
</div>

<section class="profile-feedback" id="community-feedback" aria-labelledby="profile-feedback-title">
    <header class="profile-feedback-heading">
        <h2 id="profile-feedback-title">Community <em>feedback.</em></h2>
        <p>From students who completed a thesis review.</p>
    </header>
    <div class="profile-feedback-summary profile-surface">
        <div class="profile-score">
            @if($ratings->total())
                <strong>{{ number_format($evaluator->ratings_received_avg_rating, 1) }}</strong><span>out of 5 stars</span>
            @else
                <strong aria-hidden="true">—</strong><span>No ratings yet</span>
            @endif
            <p>{{ $ratings->total() }} {{ Str::plural('rating', $ratings->total()) }}</p>
        </div>
        <div class="profile-rating-distribution" aria-label="Rating breakdown" role="group">
            @foreach($ratingDistribution as $stars => $count)
                <div class="profile-rating-row" data-rating-stars="{{ $stars }}" data-rating-count="{{ $count }}" role="group" aria-label="{{ $stars }} {{ Str::plural('star', $stars) }}: {{ $count }} {{ Str::plural('rating', $count) }}">
                    <span class="profile-rating-label" aria-hidden="true">{{ $stars }}<x-icon name="star"/></span>
                    <span class="profile-rating-track" aria-hidden="true"><span style="width:{{ $ratings->total() ? round($count / $ratings->total() * 100, 2) : 0 }}%"></span></span>
                    <span class="profile-rating-count" aria-hidden="true">{{ $count }}</span>
                </div>
            @endforeach
        </div>
    </div>
    @if($ratings->count())
        <div class="profile-feedback-grid">
            @foreach($ratings as $rating)
                <article class="profile-feedback-card profile-surface">
                    <div class="profile-feedback-stars" role="img" aria-label="{{ $rating->rating }} out of 5 stars">@foreach(range(1, 5) as $star)<x-icon name="star" :class="$star <= $rating->rating ? 'is-filled' : ''"/>@endforeach</div>
                    <p class="profile-feedback-comment">{{ filled($rating->comment) ? $rating->comment : 'Rated a completed thesis review.' }}</p>
                    <div class="profile-feedback-author">
                        <x-avatar :user="$rating->author" class="avatar-small"/>
                        <div><strong>{{ $rating->author?->name ?? 'Community member' }}</strong><time datetime="{{ $rating->created_at->toDateString() }}">{{ $rating->created_at->format('M j, Y') }}</time></div>
                    </div>
                </article>
            @endforeach
        </div>
    @else
        <div class="profile-feedback-empty profile-surface"><x-icon name="message-circle"/><h3>No ratings yet.</h3><p>Students can rate this evaluator after completing a thesis review.</p></div>
    @endif
    <x-pagination :paginator="$ratings"/>
</section>
@endsection
