@if($project->usesVolunteerReviews())
    @php
        $reviewerLimit = max(1, (int) $project->reviewer_limit);
        $occupiedReviewers = $project->occupiedReviewerCount();
    @endphp
    <div class="project-volunteer-progress" aria-label="Volunteer review progress">
        <div class="project-volunteer-meter">
            <span>{{ $occupiedReviewers }}/{{ $reviewerLimit }} {{ Str::plural('evaluator', $reviewerLimit) }}</span>
            <progress value="{{ $occupiedReviewers }}" max="{{ $reviewerLimit }}" aria-label="{{ $occupiedReviewers }} of {{ $reviewerLimit }} evaluator slots filled"></progress>
        </div>
        <span>{{ $project->completedReviewerCount() }} of {{ $project->reviewer_limit }} reviews completed</span>
    </div>
@endif
