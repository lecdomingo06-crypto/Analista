@extends('layouts.app')
@section('title', $tab === 'notifications' ? 'Notifications' : ($tab === 'bookmarks' ? 'Saved tutorials' : ($user->role === 'evaluator' ? 'My reviews' : 'Review requests')))
@section('content')
<div class="page-header">
    @if($tab === 'notifications')
        <div><p class="eyebrow">YOUR SPACE / NOTIFICATIONS</p><h1>Notifications<span class="muted">.</span></h1><p class="muted">Your review conversations and community updates, together.</p></div>
    @elseif($tab === 'bookmarks')
        <div><p class="eyebrow">YOUR SPACE / KEEP LEARNING</p><h1>Saved tutorials<span class="muted">.</span></h1><p class="muted">Videos and guides you saved for your next draft.</p></div>
    @else
        <div><p class="eyebrow">YOUR SPACE / REVIEW REQUESTS</p><h1>{{ $user->role === 'evaluator' ? 'My reviews' : 'Review requests' }}<span class="muted">.</span></h1><p class="muted">{{ $user->role === 'evaluator' ? 'Accept a request, review its thesis, and send your final recommendations.' : 'Follow your requests from the evaluator’s queue to the completed review.' }}</p></div>
        @if($user->role === 'evaluator')<a class="btn" href="{{ route($user->isEvaluator() ? 'account.profile' : 'account.evaluator-verification.show') }}">{{ $user->isEvaluator() ? 'Manage availability' : 'Manage verification' }} <x-icon name="arrow-up-right"/></a>@else<a class="btn btn-primary" href="{{ route('evaluators.index') }}">Find an evaluator <x-icon name="arrow-up-right"/></a>@endif
    @endif
</div>
@if($user->role === 'evaluator' && $user->evaluator_status !== 'approved')
<div class="panel list-item"><div><strong>Your evaluator application is {{ str_replace('_', ' ', $user->evaluator_status) }}.</strong><p class="muted small">Professional evidence and a practice review need admin approval before you appear in the directory or accept requests.</p></div><a class="btn btn-small" href="{{ route('account.evaluator-verification.show') }}">View verification</a></div>
@endif
@if($tab === 'reviews')
<div class="stats-grid">
    <div class="stat"><span class="mono">{{ str_pad((string) ($user->role === 'evaluator' ? $stats['pending_reviews'] : $stats['pending_requests']), 2, '0', STR_PAD_LEFT) }}</span><span class="section-label">PENDING REQUESTS</span></div>
    <div class="stat"><span class="mono">{{ str_pad((string) ($user->role === 'evaluator' ? $stats['active_reviews'] : $stats['active_requests']), 2, '0', STR_PAD_LEFT) }}</span><span class="section-label">IN REVIEW</span></div>
    <div class="stat"><span class="mono">{{ str_pad((string) ($user->role === 'evaluator' ? $stats['completed_reviews'] : $stats['completed_requests']), 2, '0', STR_PAD_LEFT) }}</span><span class="section-label">COMPLETED REVIEWS</span></div>
</div>
@endif
@if($tab !== 'notifications')
@if(in_array($user->role, ['student', 'evaluator'], true))
    @include('account.partials.workspace-menu', ['current' => $tab])
@else
<nav class="tabs" aria-label="Your space sections">
    @foreach(['administration' => 'Administration', 'bookmarks' => 'Saved tutorials'] as $key => $label)
        <a href="{{ $key === 'administration' ? route('admin.index') : route('tutorials.index', ['saved' => 1]) }}" class="tab {{ $tab === $key ? 'active' : '' }}" @if($tab === $key) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
@endif
@endif

@if($tab === 'projects')
    <div class="section-label">01 / THE WORK IN PROGRESS</div>
    @if($projects->isNotEmpty())
        @include('account.partials.project-list')
    @else
        @if(in_array($user->role, ['student','admin']))<x-empty title="Your thesis starts here." text="Create a project, upload your PDF, and connect with a volunteer evaluator as soon as you submit." /><a class="btn btn-primary" href="{{ route('projects.create') }}">Create your first project <x-icon name="arrow-up-right" /></a>@else<x-empty title="Your perspective makes a difference." text="Visit Review requests to find the thesis projects assigned to you, or update your profile so students can find your expertise." />@endif
    @endif
@elseif($tab === 'reviews')
    <nav class="tabs" aria-label="Filter reviews">@foreach(['' => 'All', 'pending' => 'Pending', 'accepted' => 'In review', 'completed' => 'Completed', 'declined' => 'Declined'] as $value => $label)<a class="tab {{ $reviewStatus === $value ? 'active' : '' }}" href="{{ route('account.reviews', $value === '' ? [] : ['status' => $value]) }}" @if($reviewStatus === $value) aria-current="page" @endif>{{ $label }}</a>@endforeach</nav>
    @if($user->role === 'evaluator')
        <div class="section-label">01 / ASSIGNED TO YOU</div>
        @forelse($assignedReviews as $review)
            <div class="panel list-item"><div><div class="actions"><x-status :status="$review->status" />@if(! in_array($review->project->status, ['approved', 'completed'], true))<span class="badge">Project not ready for review</span>@endif</div><h3><a href="{{ route('reviews.show', $review) }}">{{ $review->project->title }}</a></h3><p class="muted small">Requested by {{ $review->requester->name }} · {{ $review->created_at->diffForHumans() }}</p><p class="small">{{ \Illuminate\Support\Str::limit($review->message, 160) }}</p>@if($review->preferred_date)<p class="hint">Preferred completion: {{ $review->preferred_date->format('M j, Y') }}@if($review->agreed_date) · Agreed: {{ $review->agreed_date->format('M j, Y') }}@endif</p>@endif</div><a class="btn btn-small" href="{{ route('reviews.show', $review) }}">{{ $review->status === 'pending' ? 'View request' : 'Open review' }} <x-icon name="arrow-up-right" /></a></div>
        @empty<x-empty title="A little room to help." text="New thesis review requests will appear here. Keep your expertise and availability up to date so students can find you." />@endforelse
    @endif
    <div class="section-label">{{ $user->role === 'evaluator' ? '02' : '01' }} / YOUR SENT REQUESTS</div>
    @forelse($reviewRequests as $review)
        <div class="panel list-item"><div><x-status :status="$review->status" /><h3><a href="{{ route('reviews.show', $review) }}">{{ $review->project->title }}</a></h3><p class="muted small">Evaluator: {{ $review->evaluator->name }} · Sent {{ $review->created_at->diffForHumans() }}</p>@if($review->agreed_date)<p class="hint">Agreed completion: {{ $review->agreed_date->format('M j, Y') }}</p>@endif</div><a class="btn btn-small" href="{{ route('reviews.show', $review) }}">View review <x-icon name="arrow-up-right" /></a></div>
    @empty<x-empty title="No requests sent yet." text="Submit your project, then choose an evaluator whose expertise fits your thesis." />@endforelse
@elseif($tab === 'notifications')
    <div class="list-item"><span class="section-label">01 / RECENT UPDATES</span>@if($stats['unread_notifications'])<form method="POST" action="{{ route('account.notifications.readall') }}">@csrf<button class="btn btn-small btn-ghost" type="submit">Mark all as read</button></form>@endif</div>
    @forelse($activities as $activity)
        <div class="panel list-item"><div><div class="actions"><strong>{{ $activity->title }}</strong>@unless($activity->read_at)<span class="badge">New</span>@endunless</div><p class="muted small">{{ $activity->body }}</p><span class="hint">{{ $activity->created_at->diffForHumans() }}</span></div><div class="actions"><a class="btn btn-small" href="{{ $activity->url }}">Open <x-icon name="arrow-up-right" /></a>@unless($activity->read_at)<form method="POST" action="{{ route('account.notifications.read', $activity) }}">@csrf<button type="submit" class="btn btn-small btn-ghost">Mark read</button></form>@endunless</div></div>
    @empty<x-empty title="You're all caught up." text="Approval decisions, review requests, and new recommendations will show up here." />@endforelse
    <x-pagination :paginator="$activities" />
    <section class="section-block">
        <div class="section-heading"><div><p class="section-label">02 / PROJECT CONVERSATIONS</p><h2>Your review conversations</h2><p class="small muted">Open a completed review to read the summary. Use Messages for questions and further recommendations.</p></div></div>
        @forelse($conversations as $review)
            <a class="panel list-item" href="{{ route('reviews.show', $review) }}"><div><span class="section-label">{{ (int) $review->evaluator_id === (int) $user->id ? $review->requester->name : $review->evaluator->name }}</span><h3>{{ $review->project->title }}</h3><span class="hint">Updated {{ $review->updated_at->diffForHumans() }}</span></div><div class="actions"><x-status :status="$review->status" /><x-icon name="arrow-up-right" /></div></a>
        @empty<x-empty title="No review conversations yet." text="Your review requests and completed reviews will appear here." />@endforelse
    </section>
@elseif($tab === 'bookmarks')
    <div class="section-label">01 / KEEP LEARNING</div>
    @forelse($bookmarks as $tutorial)
        <div class="panel list-item"><div><span class="section-label">{{ $tutorial->category }} · {{ $tutorial->is_video ? $tutorial->video_creator.' / VIDEO' : $tutorial->reading_minutes.' MIN READ' }}</span><h3><a href="{{ route('tutorials.show', $tutorial) }}">{{ $tutorial->title }}</a></h3><p class="muted small">{{ $tutorial->excerpt }}</p></div><a class="btn btn-small" href="{{ route('tutorials.show', $tutorial) }}">{{ $tutorial->is_video ? 'Watch video' : 'Read guide' }} <x-icon name="arrow-up-right" /></a></div>
    @empty<x-empty title="Keep something for later." text="Save a useful tutorial and it will be waiting for you here." /><a class="btn" href="{{ route('tutorials.index') }}">Explore tutorials <x-icon name="arrow-up-right" /></a>@endforelse
@endif
@endsection
