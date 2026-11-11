@php
    $cardReviewState = match ($project->status) {
        'draft' => ['edit', 'Continue your submission', 'Submit your project when ready.'],
        'hidden' => ['lock', 'Project hidden', 'Read the admin note in your project.'],
        'needs_changes' => ['edit', 'Changes requested', 'Edit and resubmit your project.'],
        'rejected' => ['file-text', 'Project not approved', 'Read the admin decision note.'],
        'pending' => ['clock', 'Submission pending', 'Check your project for updates.'],
        default => null,
    };

    if ($cardReviewState === null && ! ($project->usesVolunteerReviews() && $project->status === 'approved')) {
        $cardActiveReviews = $reviews->where('status', 'accepted')->count();
        $cardCompletedReviews = $reviews->where('status', 'completed')->count();
        $cardReviewState = match (true) {
            $cardActiveReviews > 0 => ['file-text', 'Review in progress', $cardCompletedReviews > 0
                ? $cardCompletedReviews.' completed · '.$cardActiveReviews.' in progress.'
                : 'Your evaluator is reviewing.', 'ongoing'],
            $cardCompletedReviews > 0 => ['check-check', 'Review completed', 'Read evaluator recommendations.', 'completed'],
            $project->status === 'completed' => ['check-check', 'Project completed', 'No review results to display.', 'completed'],
            $reviews->contains('status', 'pending') => ['clock', 'Waiting for a response', 'Your review request has been sent.'],
            $reviews->isNotEmpty() => ['users', 'No active reviews', 'Try another available evaluator.'],
            default => ['users', 'No reviews yet', 'Find an evaluator to get started.'],
        };
    }
@endphp
@if($cardReviewState)
    <div @class(['my-project-review-state', 'is-completed' => ($cardReviewState[3] ?? null) === 'completed', 'is-ongoing' => ($cardReviewState[3] ?? null) === 'ongoing'])>
        <strong><x-icon :name="$cardReviewState[0]"/>{{ $cardReviewState[1] }}</strong>
        <p>{{ $cardReviewState[2] }}</p>
    </div>
@else
    <div class="my-project-review-state is-ongoing">
        @include('projects.partials.review-progress')
    </div>
@endif
