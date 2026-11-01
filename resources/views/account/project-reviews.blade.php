@extends('layouts.app')
@section('title', 'Review evaluators · '.$project->title)
@section('body-class', 'projects-page project-reviews-page')
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/projects.css') }}?v={{ filemtime(public_path('assets/projects.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/project-reviews.css') }}?v={{ filemtime(public_path('assets/project-reviews.css')) }}">
@endpush
@section('content')
@include('projects.partials.sky')
<a class="projects-back page-back project-reviews-back" href="{{ route('account.projects') }}"><x-icon name="arrow-left"/>Back to my projects</a>
<header class="project-reviews-header">
    <h1>Reviews by <em>evaluator.</em></h1>
    <p class="project-reviews-title">{{ $project->title }}</p>
    <p class="hint">Choose an evaluator to open their review and recommendations.</p>
</header>
<p class="project-reviews-count">{{ $reviews->total() }} {{ Str::plural('review', $reviews->total()) }}</p>
@if($reviews->isNotEmpty())
    <div class="project-reviewer-grid" aria-label="Evaluators for this thesis">
        @foreach($reviews as $review)
            @php
                $reviewDate = $review->status === 'completed' ? $review->completed_at : $review->agreed_date;
                $dateLabel = $review->status === 'completed' ? 'Completed' : 'Agreed completion';
            @endphp
            <a class="project-reviewer-card" href="{{ route('reviews.show', $review) }}{{ $review->status === 'completed' ? '#review-summary' : '' }}" aria-labelledby="project-reviewer-name-{{ $review->id }}" aria-describedby="project-reviewer-facts-{{ $review->id }}" data-review-id="{{ $review->id }}">
                <div class="project-reviewer-card-top"><x-avatar :user="$review->evaluator"/><x-status :status="$review->status"/></div>
                <h2 class="project-reviewer-name" id="project-reviewer-name-{{ $review->id }}">{{ $review->evaluator?->name ?? 'Evaluator' }}</h2>
                <p class="project-reviewer-headline">{{ $review->evaluator?->headline ?: 'Thesis evaluator' }}</p>
                @if($review->evaluator?->expertise)<div class="project-reviewer-tags"><x-tags :tags="array_slice($review->evaluator->expertise, 0, 3)"/></div>@endif
                <dl class="project-reviewer-facts" id="project-reviewer-facts-{{ $review->id }}">
                    <div><dt>Thesis PDF</dt><dd>{{ $review->document ? 'Version '.$review->document->version : 'Version unavailable' }}</dd></div>
                    <div><dt>{{ $dateLabel }}</dt><dd>@if($reviewDate)<time datetime="{{ $reviewDate->toDateString() }}">{{ $reviewDate->format('M j, Y') }}</time>@else{{ $review->status === 'completed' ? 'Date unavailable' : 'Not confirmed' }}@endif</dd></div>
                </dl>
                <span class="project-reviewer-open">{{ $review->status === 'completed' ? 'View review' : 'Review progress' }} <x-icon name="arrow-up-right"/></span>
            </a>
        @endforeach
    </div>
    <x-pagination :paginator="$reviews"/>
@else
    <x-empty title="No evaluator reviews yet." text="Evaluators assigned to this thesis will appear here once a review is requested."><a class="btn" href="{{ route('account.projects') }}">Back to my projects <x-icon name="arrow-right"/></a></x-empty>
@endif
@endsection
