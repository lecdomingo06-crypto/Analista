@extends('layouts.app')
@section('title', $project->title)
@section('body-class', 'projects-page project-detail-page')
@push('styles')
<link rel="stylesheet" href="{{ asset('assets/reviews.css') }}?v={{ filemtime(public_path('assets/reviews.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/projects.css') }}?v={{ filemtime(public_path('assets/projects.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/project-detail.css') }}?v={{ filemtime(public_path('assets/project-detail.css')) }}">
@endpush
@section('content')
@php
    $showProjectManagement = $canEdit && $canViewPrivate;
    $showPrivateProjectDetails = $canManage && $canViewPrivate;
@endphp
@include('projects.partials.sky')
<a class="projects-back page-back project-detail-back" href="{{ route($isProjectWorkspace ? 'account.projects' : 'projects.index') }}"><x-icon name="arrow-left"/>{{ $isProjectWorkspace ? 'Back to my projects' : 'Back to projects' }}</a>

<header class="project-detail-header project-detail-surface">
    <div class="project-detail-heading-row">
        <div class="project-detail-badges">
            <span class="project-detail-status"><x-status :status="$project->status"/></span>
            @if($project->category)<span class="thesis-project-tag thesis-project-tag-category">{{ $project->category }}</span>@endif
        </div>
        @if($showProjectManagement)
            <a class="btn btn-primary" href="{{ route('projects.edit', $project) }}"><x-icon name="pencil"/>Edit project</a>
        @elseif($canEdit)
            <a class="btn btn-primary" href="{{ route('account.projects.show', $project) }}">Manage project <x-icon name="arrow-up-right"/></a>
        @endif
    </div>
    <h1>{{ $project->title }}</h1>
    <p class="project-detail-affiliation"><span>{{ $project->course }}</span>@if($project->course && $project->school)<span aria-hidden="true">·</span>@endif<span>{{ $project->school }}</span></p>
</header>

@if($showPrivateProjectDetails && $project->moderation_note)
    <section class="project-detail-notice project-detail-surface" aria-labelledby="project-admin-note">
        <h2 id="project-admin-note">A note from the admin team</h2>
        <p>{{ $project->moderation_note }}</p>
        @if($showProjectManagement && in_array($project->status, ['needs_changes', 'rejected']))<a class="btn" href="{{ route('projects.edit', $project) }}">Update and resubmit <x-icon name="arrow-right"/></a>@endif
    </section>
@endif
@if($showProjectManagement && $project->status === 'pending')<div class="project-detail-notice project-detail-surface"><p><x-icon name="clock"/> This project is not ready for review. Edit and save the project to submit it.</p></div>@endif
@if($showProjectManagement && in_array($project->status, ['approved', 'completed'], true) && ! $project->is_public)
    <div class="project-detail-notice project-detail-surface"><p><strong>This project is saved privately.</strong> To list its summary in community projects, edit the project and enable summary sharing.</p><a class="btn" href="{{ route('projects.edit', $project) }}">Edit sharing <x-icon name="arrow-right"/></a></div>
@endif
<div class="project-detail-layout">
<div class="project-detail-main">
    <section class="project-detail-about project-detail-surface" aria-labelledby="project-idea-title">
        <h2 id="project-idea-title">The idea</h2>
        <p class="project-detail-summary">{{ $project->summary }}</p>
        @if(count($project->technologies ?? []))
            <div class="project-detail-section">
                <h3>Built with</h3>
                <div class="project-detail-tags">@foreach($project->technologies as $technology)<span class="thesis-project-tag">{{ $technology }}</span>@endforeach</div>
            </div>
        @endif
        <div class="project-detail-section">
            <h3>Where we could use your perspective</h3>
            <p class="project-detail-summary">{{ $project->feedback_needed }}</p>
        </div>
    </section>
    @if($canViewDocuments)
        <section class="project-detail-documents project-detail-surface" aria-labelledby="project-documents-title">
            <div class="project-detail-card-heading"><h2 id="project-documents-title">{{ $canBrowseDocuments ? 'Thesis documents' : 'Private documents' }}</h2><x-icon name="lock-keyhole"/></div>
            @if($canBrowseDocuments)
                <p class="hint">The project team has shared these thesis PDF versions with approved evaluators. Review feedback and annotated PDFs remain private.</p>
            @else
                <p>{{ $canEdit ? 'A record of your progress.' : 'Submitted thesis PDF versions.' }}</p><p class="hint">Reviews stay attached to the exact PDF version submitted with the request.</p>
            @endif
            @forelse($documents as $document)
                <div class="list-item project-detail-file"><div class="actions"><x-icon name="file-text"/><div><strong>{{ $document->original_name }}</strong><div class="small muted">Version {{ $document->version }} · {{ number_format($document->size / 1024 / 1024, 2) }} MB · {{ $document->created_at->format('M j, Y') }}</div></div></div><a class="btn btn-small" href="{{ route('documents.download', $document) }}"><x-icon name="download"/> Download PDF</a></div>
            @empty
                <p class="muted">There are no documents available to you for this project.</p>
            @endforelse
            @if($showProjectManagement)
                <hr><form class="fields" method="POST" enctype="multipart/form-data" action="{{ route('projects.upload', $project) }}">@csrf<div class="field"><label for="revision">Upload a revision</label><input id="revision" type="file" name="document" accept=".pdf,application/pdf" required><p class="hint">PDF up to 20 MB. Uploading adds a new version; existing review files stay intact.</p></div><button class="btn btn-small" type="submit"><x-icon name="upload"/> Add document version</button></form>
            @endif
        </section>
    @endif
    @if($canViewPrivate)
        <section class="project-detail-reviews project-detail-surface" aria-labelledby="project-reviews-title"><div class="project-detail-card-heading"><h2 id="project-reviews-title">Review requests</h2><span>{{ $reviewRequests->count() }} {{ Str::plural('request', $reviewRequests->count()) }}</span></div>
            @forelse($reviewRequests as $review)
                @php($reviewLabels = ['pending' => 'Pending request', 'accepted' => 'In review', 'completed' => 'Completed', 'declined' => 'Declined'])
                <div class="list-item review-document"><div><strong>{{ $review->evaluator->name }}</strong><div class="small muted">{{ $review->created_at->format('M j, Y') }} · PDF version {{ $review->document?->version ?? '—' }}</div>@if($review->agreed_date)<p class="hint">{{ $review->status === 'completed' ? 'Agreed completion' : 'Expected completion' }} {{ $review->agreed_date->format('M j, Y') }}</p>@endif</div><div class="actions"><span class="status status-{{ $review->status }}"><span></span>{{ $reviewLabels[$review->status] ?? ucfirst($review->status) }}</span><a href="{{ route('reviews.show', $review) }}" class="btn btn-small">View review <x-icon name="arrow-up-right"/></a></div></div>
            @empty
                <p class="muted">{{ $project->status === 'approved' ? 'No reviews yet. Invite a volunteer evaluator to take a look.' : 'No reviews yet. Submit your project to start requesting feedback.' }}</p>
            @endforelse
        </section>
    @else
        <section class="project-detail-privacy project-detail-surface" aria-labelledby="project-privacy-title"><span class="project-detail-privacy-icon"><x-icon name="lock-keyhole"/></span><div><h3 id="project-privacy-title">{{ $canViewDocuments ? 'Review feedback stays private.' : 'The work behind the idea stays private.' }}</h3><p>{{ $canViewDocuments ? 'Viewing a thesis PDF does not give access to review requests, feedback, review summaries, or annotated PDFs.' : 'Thesis PDFs are available to the project team, admins, and authorized evaluators. Review feedback and annotated PDFs stay private.' }}</p></div></section>
    @endif
</div>
<aside class="project-detail-sidebar" aria-label="Project details and review requests">
    <section class="project-detail-info project-detail-surface" aria-labelledby="project-details-title">
        <h2 id="project-details-title">Project details</h2>
        <div class="project-detail-creator"><x-avatar :user="$project->owner"/><div><strong>{{ $project->owner->name }}</strong><p>Project creator</p></div></div>
        <dl class="project-detail-facts">
            <div><dt>Preferred completion</dt><dd>{{ $project->deadline?->format('M j, Y') ?? 'Flexible' }}</dd></div>
            <div><dt>Posted</dt><dd>{{ $project->created_at->format('M j, Y') }}</dd></div>
        </dl>
        @if($showPrivateProjectDetails)
            <div class="project-detail-sharing"><h3>{{ $project->is_public ? 'Public summary' : 'Private project' }}</h3><p>{{ $project->is_public ? (in_array($project->status, ['approved', 'completed'], true) ? 'The project summary is listed in the community directory.' : 'The project summary will appear in the directory when this project is ready for review.') : 'Visible to the project group and authorized reviewers.' }}</p></div>
            <div class="project-detail-sharing"><h3>Thesis PDF access</h3><p>{{ $project->pdf_access === \App\Models\Project::PDF_ACCESS_APPROVED_EVALUATORS ? 'All approved evaluators' : 'Assigned evaluators only' }}</p>@if($project->pdf_access === \App\Models\Project::PDF_ACCESS_APPROVED_EVALUATORS && (! $project->is_public || ! in_array($project->status, ['approved', 'completed'], true)))<p>Broader evaluator access starts when your summary is shared and the project is ready for review.</p>@endif</div>
            @if($project->members->count())<div class="project-detail-members"><h3>Group members</h3>@foreach($project->members as $member)<p>{{ $member->name }}</p>@endforeach</div>@endif
        @endif
    </section>
    @include('projects.partials.volunteer-review')
    @if($canViewPrivate && $isProjectMember && $project->status === 'approved' && ! $project->usesVolunteerReviews())
        <section class="project-detail-request project-detail-surface fields" id="request-review" aria-labelledby="project-request-title"><h2 id="project-request-title">Request a thesis review.</h2><p>Give your evaluator a clear scope. All reviews are free and voluntary.</p>
            @if($evaluators->count() && $documents->count())
            <form class="fields" method="POST" action="{{ route('reviews.store', $project) }}">@csrf
                <div class="field"><label for="evaluator_id">Volunteer evaluator</label><select name="evaluator_id" id="evaluator_id" required><option value="">Choose an evaluator</option>@foreach($evaluators as $evaluator)<option value="{{ $evaluator->id }}" @selected((string) old('evaluator_id', request('evaluator')) === (string) $evaluator->id)>{{ $evaluator->name }}{{ $evaluator->headline ? ' · '.$evaluator->headline : '' }}</option>@endforeach</select></div>
                <div class="field"><label for="document_version_id">PDF version to review</label><select name="document_version_id" id="document_version_id" required>@foreach($documents as $document)<option value="{{ $document->id }}" @selected((string) old('document_version_id', $documents->first()->id) === (string) $document->id)>Version {{ $document->version }} · {{ $document->created_at->format('M j') }}</option>@endforeach</select></div>
                <div class="field"><label for="message">What would you like help with?</label><textarea name="message" id="message" minlength="10" maxlength="3000" required rows="4" placeholder="Introduce your project and the sections you would like checked.">{{ old('message') }}</textarea></div>
                <div class="field"><label for="preferred_date">Preferred completion date</label><input type="date" name="preferred_date" id="preferred_date" min="{{ now()->toDateString() }}" value="{{ old('preferred_date', $project->deadline?->isFuture() ? $project->deadline->format('Y-m-d') : '') }}"></div>
                <button class="btn btn-primary" type="submit">Send request <x-icon name="arrow-up-right"/></button>
            </form>
            @elseif(!$documents->count())<p class="small muted">Upload your thesis PDF in Private documents to send a review request.</p>@else <p class="small muted">No volunteer evaluators are available right now. You can check again later.</p>@endif
        </section>
    @endif
    @auth @unless($isProjectMember)<x-report type="project" :id="$project->id"/>@endunless @endauth
</aside>
</div>
@endsection
