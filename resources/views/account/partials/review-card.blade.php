@php
    $reviewPerson = $assignedToViewer ? $review->requester : $review->evaluator;
    $reviewDate = $review->agreed_date ?? $review->preferred_date;
    $reviewDateLabel = $review->agreed_date ? 'Agreed completion' : ($review->preferred_date ? 'Preferred completion' : 'Timeline');
    $reviewLinkLabel = $assignedToViewer ? ($review->status === 'pending' ? 'View request' : 'Open review') : 'View review';
    $reviewHeadingId = ($assignedToViewer ? 'assigned-review-' : 'project-review-').$review->id;
@endphp
<article class="account-review-card" aria-labelledby="{{ $reviewHeadingId }}">
    <div class="account-review-main">
        <h3 class="account-review-title" id="{{ $reviewHeadingId }}">{{ $review->project->title }}</h3>
        <div class="account-review-person">
            <x-avatar :user="$reviewPerson"/>
            <span class="account-review-person-copy"><span class="account-review-person-role">{{ $assignedToViewer ? 'Requested by' : 'Evaluator' }}</span><strong class="account-review-person-name">{{ $reviewPerson?->name ?? ($assignedToViewer ? 'Thesis group' : 'Evaluator') }}</strong></span>
        </div>
        <div class="account-review-meta"><x-icon name="clock"/><span>{{ $reviewDateLabel }}: @if($reviewDate)<time datetime="{{ $reviewDate->toDateString() }}">{{ $reviewDate->format('M j, Y') }}</time>@else Flexible timeline @endif</span></div>
        @if($assignedToViewer && ! in_array($review->project->status, ['approved', 'completed'], true))<p class="account-review-unavailable">Project not ready for review</p>@endif
    </div>
    <div class="account-review-side">
        <x-status :status="$review->status"/>
        <a class="btn btn-primary" href="{{ route('reviews.show', $review) }}">{{ $reviewLinkLabel }} <x-icon name="arrow-up-right"/></a>
    </div>
</article>
