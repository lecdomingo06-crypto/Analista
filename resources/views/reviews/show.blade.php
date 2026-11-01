@extends('layouts.app')
@section('title', 'Thesis review · '.$project->title)
@section('body-class', 'projects-page review-detail-page')
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/projects.css') }}?v={{ filemtime(public_path('assets/projects.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/reviews.css') }}?v={{ filemtime(public_path('assets/reviews.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/review-detail.css') }}?v={{ filemtime(public_path('assets/review-detail.css')) }}">
@endpush
@section('content')
@php
    $isMember = (int) $project->user_id === (int) auth()->id() || $project->members()->where('users.id', auth()->id())->exists();
    $reviewLabels = ['pending' => 'Pending request', 'accepted' => 'In review', 'completed' => 'Completed', 'declined' => 'Declined'];
    $messagePeer = $isEvaluator ? $review->requester : $review->evaluator;
    $progress = $review->status === 'completed' ? 3 : ($review->status === 'accepted' ? 2 : 1);
    $reviewBackToQueue = in_array(auth()->user()->role, ['student', 'evaluator'], true);
    $studentReview = auth()->user()->role === 'student' && $isMember;
    $studentCompletedReview = $studentReview && $canViewDocument && $review->status === 'completed';
@endphp
@include('projects.partials.sky')
<a class="projects-back page-back review-detail-back" href="{{ $studentReview ? route('account.projects.reviews', $project) : route($reviewBackToQueue ? 'account.reviews' : 'account.projects') }}"><x-icon name="arrow-left"/>{{ $studentReview ? 'Back to evaluators' : ($reviewBackToQueue ? 'Back to reviews' : 'Back to projects') }}</a>
<header class="review-detail-header">
    <div class="review-detail-header-meta"><span class="review-detail-private"><x-icon name="lock"/>Private review</span><span class="status status-{{ $review->status }}"><span></span>{{ $reviewLabels[$review->status] ?? ucfirst($review->status) }}</span></div>
    <h1>A different <em>perspective.</em></h1>
    <p>{{ $project->title }}</p>
</header>
@if(! in_array($project->status, ['approved', 'completed'], true))
    <div class="panel review-detail-paused"><p class="small"><x-icon name="clock"/> This review is paused because the project is not ready for review. The group can still manage its documents.</p></div>
@endif
@if($review->status !== 'declined')
    <ol class="review-progress" aria-label="Review progress">
        @foreach([1 => ['send', 'Request sent'], 2 => ['book', 'In review'], 3 => ['check-check', 'Review completed']] as $step => [$icon, $label])
            <li @class(['is-reached' => $progress >= $step]) @if($progress === $step) aria-current="step" @endif><span aria-hidden="true"><x-icon :name="$progress > $step ? 'check' : $icon"/></span>{{ $label }}</li>
        @endforeach
    </ol>
@else
    <div class="panel review-notice"><x-icon name="message-circle"/><div><strong>This evaluator was unable to accept the request.</strong><p class="small muted">{{ $project->status === 'completed' ? 'This project is already completed. Use Messages if you have additional questions.' : 'Your project is still saved. You can choose another evaluator to request a review.' }}</p>@if($project->status === 'approved')<a class="text-button" href="{{ route('evaluators.index') }}">Find an evaluator <x-icon name="arrow-right"/></a>@endif</div></div>
@endif
<div class="split review-layout">
<div class="stack review-detail-main">
    @if($studentCompletedReview)
        <section class="panel review-output" id="review-summary"><div class="review-detail-panel-heading"><span>Private review summary</span><x-icon name="lock-keyhole"/></div>@include('reviews.partials.output', ['review' => $review])</section>
        <details class="panel review-detail-request review-detail-request-collapsed">
            <summary><span>Thesis and request details</span><x-icon name="arrow-right"/></summary>
            <div class="review-detail-request-body">@include('reviews.partials.request')</div>
        </details>
    @else
        <section class="panel review-detail-request">@include('reviews.partials.request')</section>
        @if($canViewDocument && $review->status === 'completed')
            <section class="panel review-output" id="review-summary"><div class="review-detail-panel-heading"><span>Private review summary</span><x-icon name="lock-keyhole"/></div>@include('reviews.partials.output', ['review' => $review])</section>
        @endif
    @endif
    @if($isEvaluator && $review->status === 'accepted' && in_array($project->status, ['approved', 'completed'], true))
        <section class="panel" id="complete-review">
            <div class="review-detail-panel-heading"><h2>Your final review</h2><x-icon name="check-check"/></div><h3>Give the group a clear next step.</h3><p class="small muted">Complete your written review and add an annotated PDF if helpful. Sending it completes your review, notifies the group, and adds your review to Messages and My projects.@if($project->usesVolunteerReviews()) The project is completed when its reviewer target is reached and all assigned reviews have finished.@else The project will also be marked completed.@endif</p>
            <form class="fields" method="POST" enctype="multipart/form-data" action="{{ route('reviews.complete', $review) }}">@csrf
                <div class="field"><label for="final_summary">Written review summary <span aria-hidden="true">*</span></label><textarea id="final_summary" name="final_summary" required minlength="30" maxlength="10000" rows="9" placeholder="Summarize what works, the changes you recommend, and the next steps for the group." @error('final_summary') aria-invalid="true" aria-describedby="final-summary-error" @else aria-describedby="final-summary-help" @enderror>{{ old('final_summary') }}</textarea><p class="hint" id="final-summary-help">30–10,000 characters. Include practical recommendations that the group can act on.</p>@error('final_summary')<p class="small" id="final-summary-error" role="alert">{{ $message }}</p>@enderror</div>
                <div class="field"><label for="annotated_pdf">Annotated PDF (optional)</label><input id="annotated_pdf" name="annotated_pdf" type="file" accept=".pdf,application/pdf" aria-describedby="annotated-pdf-help" @error('annotated_pdf') aria-invalid="true" @enderror><p class="hint" id="annotated-pdf-help">PDF up to 20 MB. Shared privately with the thesis group; the original submitted PDF stays unchanged.</p>@error('annotated_pdf')<p class="small" role="alert">{{ $message }}</p>@enderror</div>
                <button type="submit" class="btn btn-primary"><x-icon name="send"/> Complete &amp; send review</button>
            </form>
        </section>
    @endif

</div>
<aside class="aside stack review-detail-sidebar">
    <section class="panel">
        <h2 class="review-detail-evaluator-title">Your evaluator</h2>
        <div class="actions review-detail-evaluator-person"><x-avatar :user="$review->evaluator"/><strong>{{ $review->evaluator->name }}</strong></div>
        <dl class="review-detail-facts"><div><dt>Preferred completion date</dt><dd>{{ $review->preferred_date?->format('M j, Y') ?? 'Flexible' }}</dd></div>@if($review->agreed_date)<div><dt>Confirmed by evaluator</dt><dd>{{ $review->agreed_date->format('M j, Y') }}</dd></div>@endif</dl>
        <p class="small muted">All reviews are free. Recommendations support your work; your adviser and panel handle academic approval.</p>
        <div class="fields review-detail-sidebar-actions">@if($canManage || $canViewDocument || $project->is_public)<a class="btn btn-small" href="{{ $canManage ? route('account.projects.show', $project) : route('projects.show', $project) }}">View project <x-icon name="arrow-up-right"/></a>@endif @if($messagePeer && (int) $messagePeer->id !== (int) auth()->id())<a class="btn btn-small" href="{{ route('messages.compose', $messagePeer) }}"><x-icon name="message-circle"/> Message {{ $isEvaluator ? 'the student' : 'evaluator' }}</a>@endif</div>
    </section>
    @if($isEvaluator && $review->status === 'pending' && $project->status === 'approved' && auth()->user()->isEvaluator())
        <section class="panel"><h2>Your next step</h2><h3>Can you lend a hand?</h3><p class="small muted">Accept if the topic fits your expertise and you have time to review it. The group will receive a message and notification.</p><form class="fields" method="POST" action="{{ route('reviews.respond', $review) }}">@csrf<div class="field"><label for="agreed_date">Your proposed completion date</label><input type="date" id="agreed_date" name="agreed_date" min="{{ now()->toDateString() }}" value="{{ old('agreed_date') }}"></div><button class="btn btn-primary" type="submit" name="decision" value="accept"><x-icon name="check"/> Accept &amp; start review</button><button class="btn btn-ghost" type="submit" name="decision" value="decline">Decline request</button></form></section>
    @elseif($review->status === 'pending' && $project->status === 'completed')
        <section class="panel"><x-icon name="check-check"/><h3>This project is completed.</h3><p class="small muted">Another evaluator has completed a review for this project. New reviews cannot be started. Use Messages for additional questions.</p>@if($isEvaluator && auth()->user()->isEvaluator())<form method="POST" action="{{ route('reviews.respond', $review) }}">@csrf<button class="btn btn-small btn-ghost" type="submit" name="decision" value="decline">Close this request</button></form>@endif</section>
    @elseif($review->status === 'pending')
        <section class="panel"><x-icon name="clock"/><h3>Waiting for a response.</h3><p class="small muted">{{ $review->evaluator->name }} will confirm whether they can review your project. Acceptance will appear in Messages and Notifications.</p></section>
    @elseif($review->status === 'accepted' && !$isEvaluator)
        <section class="panel"><x-icon name="file-text"/><h3>Your thesis is in review.</h3><p class="small muted">You will receive a message and notification when the evaluator sends their completed review.</p></section>
    @endif
    @if($canRate)
        <section class="panel review-rating" id="rating"><h2>Rate your evaluator</h2><h3>{{ $rating ? 'Your feedback matters.' : 'How was the review?' }}</h3><p class="small muted">Rate this completed review to help other students find useful guidance.</p><form class="fields" method="POST" action="{{ route('reviews.rate', $review) }}">@csrf
            <fieldset class="rating-fieldset"><legend>Your rating <span class="hint">(required)</span></legend><div class="rating-stars">@foreach(range(1, 5) as $value)<label class="rating-star" for="rating-{{ $value }}"><input type="radio" id="rating-{{ $value }}" name="rating" value="{{ $value }}" required @checked((string) old('rating', $rating?->rating) === (string) $value)><span><x-icon name="star"/><span aria-hidden="true">{{ $value }}</span></span><span class="sr-only">{{ $value }} {{ Str::plural('star', $value) }} out of 5</span></label>@endforeach</div></fieldset>
            <div class="field"><label for="comment">Written review (optional)</label><textarea name="comment" id="comment" rows="4" maxlength="1500" placeholder="What was helpful? What could improve?">{{ old('comment', $rating?->comment) }}</textarea><p class="hint">Your name, rating, and comment may appear on the evaluator’s profile. Keep private thesis details out of your comment.</p></div><button class="btn btn-primary" type="submit">{{ $rating ? 'Update rating' : 'Submit rating' }}</button><p class="hint">One rating per student for this review. You can edit yours later.</p>
        </form></section>
    @endif
    @auth @unless($isMember)<x-report type="project" :id="$project->id"/>@endunless @endauth
</aside>
</div>
@endsection
