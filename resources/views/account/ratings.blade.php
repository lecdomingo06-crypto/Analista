@extends('layouts.app')
@section('title', 'My ratings')
@section('body-class', 'projects-page my-ratings-page')
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/projects.css') }}?v={{ filemtime(public_path('assets/projects.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/my-ratings.css') }}?v={{ filemtime(public_path('assets/my-ratings.css')) }}">
@endpush
@section('content')
@include('projects.partials.sky')
<a class="projects-back page-back" href="{{ route('home') }}"><x-icon name="arrow-left"/>Back to home</a>
<header class="my-ratings-header">
    <h1>My <em>ratings.</em></h1>
    <p>See how students rated your reviews and read the feedback they shared with you.</p>
</header>
@include('account.partials.workspace-menu', ['current' => 'ratings'])

<section class="my-ratings-overview" aria-labelledby="my-ratings-summary-title">
    <div class="my-ratings-score">
        <h2 id="my-ratings-summary-title">Overall rating</h2>
        <div class="my-ratings-score-value">
            <strong>{{ $summary['average'] !== null ? number_format($summary['average'], 1) : '—' }}</strong>
            <span>out of 5</span>
        </div>
        @if($summary['total'] > 0)
            <p>Based on {{ number_format($summary['total']) }} {{ Str::plural('student rating', $summary['total']) }}</p>
        @else
            <p>No student ratings yet</p>
        @endif
    </div>
    <div class="my-ratings-distribution" aria-label="Rating distribution">
        @foreach(range(5, 1) as $stars)
            @php
                $count = (int) ($ratingDistribution[$stars] ?? 0);
            @endphp
            <div class="my-ratings-distribution-row">
                <span class="my-ratings-distribution-label">{{ $stars }} <x-icon name="star"/></span>
                <progress value="{{ $count }}" max="{{ max(1, $summary['total']) }}" aria-label="{{ $stars }} {{ Str::plural('star', $stars) }}: {{ $count }} {{ Str::plural('rating', $count) }}">{{ $count }}</progress>
                <span class="my-ratings-distribution-count">{{ number_format($count) }}</span>
            </div>
        @endforeach
    </div>
</section>

<section class="my-ratings-feedback" aria-labelledby="my-ratings-feedback-title">
    <div class="my-ratings-feedback-heading">
        <div>
            <h2 id="my-ratings-feedback-title">Student feedback</h2>
            <p>{{ number_format($ratings->total()) }} {{ Str::plural('rating', $ratings->total()) }}{{ $selectedRating !== '' ? ' with '.$selectedRating.' '.Str::plural('star', (int) $selectedRating) : ' received' }} · Newest first</p>
        </div>
        <form class="my-ratings-filter" method="GET" action="{{ route('account.ratings') }}" aria-label="Filter ratings">
            <label for="my-ratings-stars">Rating</label>
            <div class="my-ratings-filter-controls">
                <select id="my-ratings-stars" name="stars">
                    <option value="">All ratings</option>
                    @foreach(range(5, 1) as $stars)
                        <option value="{{ $stars }}" @selected($selectedRating === (string) $stars)>{{ $stars }} {{ Str::plural('star', $stars) }}</option>
                    @endforeach
                </select>
                <button class="btn btn-primary" type="submit">Apply</button>
                @if($selectedRating !== '')<a class="my-ratings-reset" href="{{ route('account.ratings') }}">Reset</a>@endif
            </div>
        </form>
    </div>

    @if($ratings->isNotEmpty())
        <div class="my-ratings-list">
            @foreach($ratings as $rating)
                @php
                    $review = $rating->reviewRequest;
                    $project = $review?->project;
                    $canOpenReview = $review && (int) $review->evaluator_id === (int) $user->id;
                    $expandComment = filled($rating->comment) && (Str::length($rating->comment) > 110 || substr_count($rating->comment, "\n") > 2);
                @endphp
                <article class="my-rating-card" aria-labelledby="my-rating-author-{{ $loop->index }}">
                    <header class="my-rating-card-header">
                        <div class="my-rating-author">
                            <x-avatar :user="$rating->author"/>
                            <div><h3 id="my-rating-author-{{ $loop->index }}">{{ $rating->author?->name ?? 'Former student' }}</h3><time datetime="{{ $rating->created_at->toAtomString() }}">{{ $rating->created_at->format('M j, Y') }}</time></div>
                        </div>
                    </header>
                    <div class="my-rating-score" role="img" aria-label="{{ $rating->rating }} out of 5 stars">
                        <span class="my-rating-stars" aria-hidden="true">@foreach(range(1, 5) as $star)<x-icon name="star" :class="$star <= $rating->rating ? 'is-filled' : ''"/>@endforeach</span>
                        <strong aria-hidden="true">{{ $rating->rating }}<span>/5</span></strong>
                    </div>
                    @if($expandComment)
                        <details class="my-rating-feedback-details">
                            <summary><span class="my-rating-comment-preview">{{ $rating->comment }}</span><span class="my-rating-read-more">Read more</span><span class="my-rating-read-less">Show less</span></summary>
                            <p class="my-rating-comment">{{ $rating->comment }}</p>
                        </details>
                    @elseif(filled($rating->comment))
                        <p class="my-rating-comment">{{ $rating->comment }}</p>
                    @else
                        <p class="my-rating-no-comment">No written feedback provided.</p>
                    @endif
                    <footer class="my-rating-card-footer">
                        <p><x-icon name="folder"/><span><span class="my-rating-project-label">Project</span><strong title="{{ $project?->title ?? 'Project no longer available' }}">{{ $project?->title ?? 'Project no longer available' }}</strong></span></p>
                        @if($canOpenReview)
                            <a class="my-rating-review-link" href="{{ route('reviews.show', $review) }}">View review <x-icon name="arrow-up-right"/></a>
                        @endif
                    </footer>
                </article>
            @endforeach
        </div>
        <x-pagination :paginator="$ratings"/>
    @elseif($selectedRating !== '')
        <div class="my-ratings-empty">
            <span class="my-ratings-empty-icon"><x-icon name="star"/></span>
            <h3>No ratings match this filter</h3>
            <p>Choose another rating or view all student feedback.</p>
            <a class="btn btn-primary" href="{{ route('account.ratings') }}">View all ratings</a>
        </div>
    @else
        <div class="my-ratings-empty">
            <span class="my-ratings-empty-icon"><x-icon name="star"/></span>
            <h3>No ratings yet</h3>
            <p>Students can rate you after you complete their review. Their ratings and comments will be collected here.</p>
            <a class="btn btn-primary" href="{{ route('account.reviews') }}">View my reviews <x-icon name="arrow-right"/></a>
        </div>
    @endif
</section>
@endsection
