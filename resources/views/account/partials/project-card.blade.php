@php
    $reviews = $project->reviewRequests->sortByDesc('updated_at')->values();
    $reviewLabels = ['pending' => 'Pending request', 'accepted' => 'In review', 'completed' => 'Completed', 'declined' => 'Declined'];
@endphp
<article class="panel stack my-project-card my-project-card-compact{{ $isOwner ? ' my-project-card-has-delete' : '' }}" id="my-project-{{ $project->id }}" aria-labelledby="my-project-title-{{ $project->id }}">
    @if($project->status === 'draft')
        <div class="my-project-draft"><span class="status status-draft"><x-icon name="edit"/>Saved as draft</span></div>
    @endif
    <div class="my-project-heading">
        <h3 id="my-project-title-{{ $project->id }}"><a href="{{ route('account.projects.show', $project) }}" title="{{ $project->title }}">{{ $project->title }}</a></h3>
        <p>{{ $project->school }}@if($project->course) · {{ $project->course }}@endif</p>
        @unless($isOwner)<p class="hint">Owned by {{ $project->owner?->name ?? 'Project owner' }}</p>@endunless
    </div>
    <p class="my-project-summary">{{ $project->summary }}</p>
    <div class="actions my-project-meta">
        @if($project->status === 'approved')
            <span class="status status-approved"><span></span>Ready for review</span>
        @elseif($project->status !== 'draft')
            <x-status :status="$project->status" />
        @endif
        <span class="badge my-project-role">{{ $isOwner ? 'Owner' : 'Group member' }}</span>
        @if($project->category)<span class="my-project-category">{{ $project->category }}</span>@endif
    </div>
    <div class="my-project-stats">
        <span><x-icon name="file-text"/>{{ $project->documents_count }} {{ Str::plural('document version', $project->documents_count) }}</span>
        <span><x-icon name="message-circle"/>{{ $reviews->count() }} {{ Str::plural('review request', $reviews->count()) }}</span>
    </div>
    @include('projects.partials.review-progress')
    @if($project->moderation_note)<p class="small my-project-note"><strong>Admin note:</strong> {{ $project->moderation_note }}</p>@endif
    <div class="actions my-project-actions">
        <a class="btn btn-small btn-primary my-project-open" href="{{ route('account.projects.show', $project) }}">View project <x-icon name="arrow-up-right"/></a>
        <a class="btn btn-small" href="{{ route('projects.edit', $project) }}">Edit project <x-icon name="edit"/></a>
        @if($user->role === 'student' && $reviews->isNotEmpty())
            <a class="btn btn-small" href="{{ route('account.projects.reviews', $project) }}">View review <x-icon name="arrow-up-right"/></a>
        @elseif($reviews->count() === 1)
            @php($review = $reviews->first())
            <a class="btn btn-small" href="{{ route('reviews.show', $review) }}{{ $review->status === 'completed' ? '#review-summary' : '' }}">View review <x-icon name="arrow-up-right"/></a>
        @elseif($reviews->count() > 1)
            <details class="my-project-review-menu">
                <summary class="btn btn-small">View review <x-icon name="arrow-right" class="my-project-review-toggle-icon"/></summary>
                <div class="my-project-review-options" aria-label="Project review requests">
                    @foreach($reviews as $review)
                        <a href="{{ route('reviews.show', $review) }}{{ $review->status === 'completed' ? '#review-summary' : '' }}">
                            <strong>{{ $review->evaluator?->name ?? 'Evaluator' }}</strong>
                            <span class="my-project-review-option-meta">{{ $reviewLabels[$review->status] ?? ucfirst($review->status) }} · PDF version {{ $review->document?->version ?? '—' }}</span>
                            <x-icon name="arrow-up-right"/>
                        </a>
                    @endforeach
                </div>
            </details>
        @else
            <span class="btn btn-small my-project-review-disabled" aria-disabled="true" title="No review requests yet">View review <x-icon name="arrow-up-right"/></span>
        @endif
    </div>
    @if($isOwner)
        <details class="project-delete">
            <summary class="my-project-delete-toggle" aria-label="Delete project: {{ $project->title }}" title="Delete project"><x-icon name="trash"/></summary>
            <form method="POST" action="{{ route('projects.destroy', $project) }}" class="fields">
                @csrf @method('DELETE')
                <p class="small">Delete <strong>{{ $project->title }}</strong>? This also removes its thesis files, review requests, and feedback. This cannot be undone.</p>
                <div class="field"><label for="delete-project-{{ $project->id }}">Type DELETE to confirm</label><input id="delete-project-{{ $project->id }}" name="delete_confirmation" required pattern="DELETE" autocomplete="off" placeholder="DELETE" aria-describedby="delete-help-{{ $project->id }}"><span class="hint" id="delete-help-{{ $project->id }}">Only the project owner can delete this project.</span></div>
                <button class="btn btn-small btn-danger" type="submit">Delete this project</button>
            </form>
        </details>
    @endif
</article>
