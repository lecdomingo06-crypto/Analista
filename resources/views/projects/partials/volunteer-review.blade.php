@if($project->usesVolunteerReviews())
    @php
        $volunteerViewer = auth()->user();
        $latestThesis = $project->latestDocument;
        $volunteerSlotsFull = $project->occupiedReviewerCount() >= $project->reviewer_limit;
        $volunteerAvailable = $project->status === 'approved' && $project->is_public && $latestThesis;
        $ownVolunteerReview = $volunteerViewer?->role === 'evaluator'
            ? $project->reviewRequests()->where('evaluator_id', $volunteerViewer->id)->whereIn('status', ['pending', 'accepted', 'completed'])->latest('id')->first()
            : null;
        $volunteerConflict = $volunteerViewer?->isEvaluator() && \App\Support\ProjectAccess::hasConflict($project, $volunteerViewer);
        $volunteerAtCapacity = $volunteerViewer?->isEvaluator() && (! $volunteerViewer->accepting_requests || $volunteerViewer->capacity < 1
            || \App\Models\ReviewRequest::where('evaluator_id', $volunteerViewer->id)->where('status', 'accepted')->count() >= $volunteerViewer->capacity);
    @endphp
    <section class="project-detail-request project-detail-surface fields" id="volunteer-reviews" aria-labelledby="volunteer-reviews-title">
        <h2 id="volunteer-reviews-title">Volunteer reviews</h2>
        @include('projects.partials.review-progress')
        @if($showProjectManagement)
            <p>Approved evaluators can start a review themselves, up to your chosen limit. Existing assignments remain available when you change the review mode.</p>
            @if(! $project->is_public || ! in_array($project->status, ['approved', 'completed'], true))<p class="hint">Volunteer sign-ups are paused. Submit your project and share its summary publicly to let evaluators join.</p>@endif
            <a class="btn" href="{{ route('projects.edit', $project) }}">Edit review settings <x-icon name="edit"/></a>
        @elseif($ownVolunteerReview)
            <p>{{ $ownVolunteerReview->status === 'pending' ? 'You already have an invitation to review this thesis. Open it to accept or decline.' : 'You are assigned to this thesis. Your review stays attached to its assigned PDF version.' }}</p>
            <a class="btn btn-primary" href="{{ route('reviews.show', $ownVolunteerReview) }}{{ $ownVolunteerReview->status === 'completed' ? '#review-summary' : '' }}">{{ $ownVolunteerReview->status === 'pending' ? 'View invitation' : 'View your review' }} <x-icon name="arrow-up-right"/></a>
        @elseif($project->status === 'completed')
            <p>The reviewer target has been completed. This project is closed to new volunteers.</p>
        @elseif($volunteerSlotsFull)
            <p>All reviewer slots are filled. This project is closed to new volunteers.</p>
            <button class="btn" type="button" disabled>Reviewer slots filled</button>
        @elseif(! $volunteerAvailable)
            <p>Volunteer reviews are currently unavailable for this project.</p>
        @elseif(! $volunteerViewer)
            <p>Approved evaluators can choose a completion date and start reviewing the latest thesis PDF.</p>
            <a class="btn btn-primary" href="{{ route('login') }}">Log in as an evaluator <x-icon name="arrow-right"/></a>
        @elseif(! $volunteerViewer->isEvaluator())
            <p>Only approved, active evaluators can volunteer to review this thesis.</p>
        @elseif($volunteerConflict)
            <p>You can volunteer for a thesis you are independent of. Members and contributors cannot review their own project.</p>
        @elseif($volunteerAtCapacity)
            <p>You are not currently available for another review. Check your review availability and capacity in My profile.</p>
            <a class="btn" href="{{ route('account.profile') }}#review-availability">Review availability <x-icon name="arrow-right"/></a>
        @else
            <p>Start a review of PDF version {{ $latestThesis->version }} and choose a completion date you can commit to.</p>
            <form class="fields" method="POST" action="{{ route('reviews.volunteer', $project) }}">
                @csrf
                <div class="field"><label for="volunteer-agreed-date">Expected completion date</label><input type="date" name="agreed_date" id="volunteer-agreed-date" min="{{ now()->toDateString() }}" required value="{{ old('agreed_date', $project->deadline && $project->deadline->greaterThanOrEqualTo(today()) ? $project->deadline->format('Y-m-d') : '') }}" @error('agreed_date') aria-invalid="true" aria-describedby="volunteer-date-error" @enderror>@error('agreed_date')<p class="hint" id="volunteer-date-error">{{ $message }}</p>@enderror</div>
                <div class="field"><label for="volunteer-message">Message to the thesis group <span class="hint">Optional</span></label><textarea name="message" id="volunteer-message" maxlength="3000" rows="3" @error('message') aria-invalid="true" aria-describedby="volunteer-message-error" @enderror>{{ old('message') }}</textarea>@error('message')<p class="hint" id="volunteer-message-error">{{ $message }}</p>@enderror</div>
                <p class="hint">Starting assigns this PDF version to you and fills one reviewer slot. Other evaluators’ feedback and review documents stay private.</p>
                <button class="btn btn-primary" type="submit">Start review <x-icon name="arrow-right"/></button>
            </form>
        @endif
    </section>
@endif
