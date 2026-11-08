@once
@unless(request()->routeIs('account.projects'))
@push('styles')
<link rel="stylesheet" href="{{ asset('assets/reviews.css') }}?v={{ filemtime(public_path('assets/reviews.css')) }}">
@endpush
@endunless
@endonce
@foreach($projects as $project)
    @php
        $isOwner = (int) $project->user_id === (int) $user->id;
    @endphp
    @if(request()->routeIs('account.projects'))
        @include('account.partials.project-card')
        @continue
    @endif
    <article class="panel stack my-project-card" id="my-project-{{ $project->id }}">
        <div class="actions my-project-meta">@if($project->status === 'approved')<span class="status status-approved"><span></span>Ready for review</span>@else<x-status :status="$project->status" />@endif<span class="badge my-project-role">{{ $isOwner ? 'Owner' : 'Group member' }}</span><span class="hint my-project-documents"><x-icon name="file-text"/>{{ $project->documents_count }} {{ Str::plural('document version', $project->documents_count) }}</span></div>
        <div class="my-project-heading"><h3><a href="{{ route('account.projects.show', $project) }}">{{ $project->title }}</a></h3><p class="small muted">{{ $project->school }}@if($project->course) · {{ $project->course }}@endif</p><p class="hint">Updated {{ $project->updated_at->format('M j, Y') }}@unless($isOwner) · Owned by {{ $project->owner?->name ?? 'Project owner' }}@endunless</p></div>
        @if($project->moderation_note)<p class="small my-project-note"><strong>Admin note:</strong> {{ $project->moderation_note }}</p>@endif
        <div class="actions my-project-actions">
            <a class="btn btn-small btn-primary my-project-open" href="{{ route('account.projects.show', $project) }}">View project <x-icon name="arrow-up-right" /></a>
            <a class="btn btn-small" href="{{ route('projects.edit', $project) }}">Edit project <x-icon name="edit" /></a>
        </div>
        @if($project->reviewRequests->isNotEmpty())
            <div class="project-review-list" aria-label="Project review requests">
                @foreach($project->reviewRequests->sortByDesc('updated_at') as $review)
                    @php
                        $reviewLabels = ['pending' => 'Pending request', 'accepted' => 'In review', 'completed' => 'Completed', 'declined' => 'Declined'];
                        $myRating = $review->ratings->firstWhere('user_id', $user->id);
                    @endphp
                    <div class="project-review-row"><div><p class="project-review-person">{{ $review->evaluator->name }}</p><p class="hint">PDF version {{ $review->document?->version ?? '—' }}@if($review->status === 'completed' && $review->completed_at) · Completed {{ $review->completed_at->format('M j, Y') }}@elseif($review->agreed_date) · Due {{ $review->agreed_date->format('M j, Y') }}@endif</p>@if($review->status === 'completed' && $review->final_summary)<p class="project-review-summary">{{ Str::limit($review->final_summary, 200) }}</p>@endif</div><div class="actions"><span class="status status-{{ $review->status }}"><span></span>{{ $reviewLabels[$review->status] ?? ucfirst($review->status) }}</span><a class="btn btn-small" href="{{ route('reviews.show', $review) }}{{ $review->status === 'completed' ? '#review-summary' : '' }}">View review <x-icon name="arrow-up-right"/></a>@if($review->status === 'completed' && $user->role === 'student')<a class="btn btn-small" href="{{ route('reviews.show', $review) }}#rating"><x-icon name="star"/>{{ $myRating ? 'Edit rating' : 'Rate evaluator' }}</a>@endif</div></div>
                @endforeach
            </div>
        @elseif($project->status === 'approved')
            <div class="project-review-empty"><p class="hint">No review requests yet.</p><a class="text-button" href="{{ route('evaluators.index') }}">Find an evaluator <x-icon name="arrow-right"/></a></div>
        @endif
        @if($isOwner)
        <details class="project-delete">
            <summary class="text-button">Delete project</summary>
            <form method="POST" action="{{ route('projects.destroy', $project) }}" class="fields" data-confirm="Are you sure you want to delete this project? Its thesis files, review requests, and feedback will also be removed. This cannot be undone." data-confirm-title="Delete project?" data-confirm-label="Delete project" data-confirm-subject="{{ $project->title }}">
                @csrf @method('DELETE')
                <p class="small">Delete <strong>{{ $project->title }}</strong>? This also removes its thesis files, review requests, and feedback. This cannot be undone.</p>
                <div class="field"><label for="delete-project-{{ $project->id }}">Type DELETE to confirm</label><input id="delete-project-{{ $project->id }}" name="delete_confirmation" required pattern="DELETE" autocomplete="off" placeholder="DELETE" aria-describedby="delete-help-{{ $project->id }}"><span class="hint" id="delete-help-{{ $project->id }}">Only the project owner can delete this project.</span></div>
                <button class="btn btn-small btn-danger" type="submit">Delete this project</button>
            </form>
        </details>
        @endif
    </article>
@endforeach
