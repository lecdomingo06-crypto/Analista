@extends('layouts.app')
@section('title', $user->role === 'evaluator' ? 'My reviews' : 'Review requests')
@section('body-class', 'projects-page account-reviews-page')
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/projects.css') }}?v={{ filemtime(public_path('assets/projects.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/account-reviews.css') }}?v={{ filemtime(public_path('assets/account-reviews.css')) }}">
@endpush
@section('content')
@php
    $isEvaluatorQueue = $user->role === 'evaluator';
    $visibleReviews = $isEvaluatorQueue ? $assignedReviews : $reviewRequests;
@endphp
@include('projects.partials.sky')
<header class="account-reviews-header">
    <h1>@if($isEvaluatorQueue)My <em>reviews.</em>@else Review <em>requests.</em>@endif</h1>
    <p>{{ $isEvaluatorQueue ? 'Your requests, ongoing reviews, and completed feedback in one place.' : 'Follow each evaluator’s progress and open your thesis feedback.' }}</p>
</header>

@if(in_array($user->role, ['student', 'evaluator'], true))
    @include('account.partials.workspace-menu', ['current' => 'reviews'])
@else
<nav class="tabs account-reviews-tabs" aria-label="Your space sections">
    <a class="tab" href="{{ route('account.projects') }}">My projects</a>
    <a class="tab active" href="{{ route('account.reviews') }}" aria-current="page">{{ $isEvaluatorQueue ? 'My reviews' : 'Review requests' }}</a>
    <a class="tab" href="{{ route('tutorials.index', ['saved' => 1]) }}">Saved tutorials</a>
</nav>
@endif

<div class="account-reviews-board">
@if($isEvaluatorQueue && $user->evaluator_status !== 'approved')
    <aside class="panel account-reviews-notice" aria-label="Evaluator application status">
        <div><strong>Your evaluator application is {{ str_replace('_', ' ', $user->evaluator_status) }}.</strong><p>Professional evidence and a practice review need admin approval before you appear in the directory or accept requests.</p></div>
        <a class="btn" href="{{ route('account.evaluator-verification.show') }}">View verification <x-icon name="arrow-up-right"/></a>
    </aside>
@endif

<div class="account-reviews-toolbar">
    <div class="stats-grid account-reviews-stats" aria-label="Review totals">
        @foreach(['pending' => 'Pending', 'active' => 'In review', 'completed' => 'Completed'] as $key => $label)
            <div class="account-review-stat">
                <span class="mono account-review-stat-count">{{ $stats[$key.($isEvaluatorQueue ? '_reviews' : '_requests')] }}</span>
                <span class="account-review-stat-label">{{ $label }}</span>
            </div>
        @endforeach
    </div>
    <form class="account-reviews-filter" method="GET" action="{{ route('account.reviews') }}" aria-label="Filter reviews">
        <label for="review-status">Status</label>
        <select id="review-status" name="status">
            @foreach(['' => 'All statuses', 'pending' => 'Pending', 'accepted' => 'In review', 'completed' => 'Completed', 'declined' => 'Declined'] as $value => $label)
                <option value="{{ $value }}" @selected($reviewStatus === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <button class="btn btn-primary" type="submit">Filter <x-icon name="arrow-right"/></button>
    </form>
</div>

    <section class="account-reviews-section" aria-labelledby="account-review-list-title">
        <div class="account-reviews-section-heading"><h2 id="account-review-list-title">{{ $isEvaluatorQueue ? 'Assigned to you' : 'Reviews for your projects' }}</h2><span>{{ $visibleReviews->count() }} {{ Str::plural('review', $visibleReviews->count()) }}</span></div>
        @if($visibleReviews->isNotEmpty())
            <div class="account-reviews-grid">
                @foreach($visibleReviews as $review)
                    @include('account.partials.review-card', ['assignedToViewer' => $isEvaluatorQueue])
                @endforeach
            </div>
        @elseif($reviewStatus !== '')
            <x-empty title="No reviews with this status." text="Choose another status above to see your reviews."/>
        @elseif($isEvaluatorQueue)
            <x-empty title="A little room to help." text="New thesis review requests will appear here. Keep your expertise and availability up to date so students can find you."/>
        @else
            <x-empty title="Your reviews will appear here." text="Review requests and volunteer reviews for your projects will be kept together here."/>
        @endif
    </section>
</div>
@endsection
