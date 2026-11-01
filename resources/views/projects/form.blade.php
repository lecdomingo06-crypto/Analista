@extends('layouts.app')
@section('title', $project->exists ? 'Edit your project' : 'Submit a thesis project')
@section('body-class', 'projects-page project-form-page')
@section('project-validation-title', $project->exists ? 'Your changes were not saved. Please fix these fields:' : 'Your project has not been created yet. Please fix these fields:')
@section('project-validation-help', $project->exists ? 'Your entered details are still below. Correct the errors and save again.' : 'Your entered details are still below. Correct the errors, select your thesis PDF again, and submit.')
@push('styles')
    <link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('assets/projects.css') }}?v={{ filemtime(public_path('assets/projects.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/project-form.css') }}?v={{ filemtime(public_path('assets/project-form.css')) }}">
@endpush
@push('scripts')
    <script src="{{ asset('assets/project-form.js') }}?v={{ filemtime(public_path('assets/project-form.js')) }}" defer></script>
@endpush
@section('content')
@include('projects.partials.sky')
<a class="projects-back page-back project-form-back" href="{{ route('account.projects') }}"><x-icon name="arrow-left"/>Back to my projects</a>
<header class="project-form-header">
    <h1>@if($project->exists)Refine the <em>details.</em>@else Your next <em>chapter.</em>@endif</h1>
    <p>Tell the community what you are building and where you could use a fresh perspective.</p>
</header>
@unless($errors->any())<div class="alert" data-project-errors role="alert" tabindex="-1" hidden></div>@endunless
<div class="project-form-layout">
<form class="panel fields project-form-card" data-project-form data-project-mode="{{ $project->exists ? 'edit' : 'create' }}" method="POST" enctype="multipart/form-data" action="{{ $project->exists ? route('projects.update', $project) : route('projects.store') }}">
    @csrf @if($project->exists) @method('PUT') @endif
    @if(old('evaluator_id', $selectedEvaluatorId ?? null))<input type="hidden" name="evaluator_id" value="{{ old('evaluator_id', $selectedEvaluatorId ?? null) }}">@endif
    <h2 class="project-form-section-heading">Project details</h2>
    <div class="field"><label for="title">Thesis or capstone title <span class="muted">*</span></label><input id="title" name="title" required maxlength="200" value="{{ old('title', $project->title) }}" placeholder="A clear, specific title for your research"></div>
    <div class="field"><label for="summary">Project summary <span class="muted">*</span></label><textarea id="summary" name="summary" required minlength="30" maxlength="5000" rows="5" placeholder="Explain the problem, your proposed solution, and who it helps.">{{ old('summary', $project->summary) }}</textarea><p class="hint">30–5,000 characters. This summary can be shown in the community directory.</p></div>
    <div class="form-grid">
        <div class="field"><label for="school">School / university <span class="muted">*</span></label><input id="school" name="school" required maxlength="200" value="{{ old('school', $project->school) }}" placeholder="Your school"></div>
        <div class="field"><label for="course">Course / program <span class="muted">*</span></label><input id="course" name="course" required maxlength="150" value="{{ old('course', $project->course) }}" placeholder="e.g. BS Information Technology"></div>
        <div class="field"><label for="category">Area of focus <span class="muted">*</span></label><select id="category" name="category" required><option value="">Select a category</option>@foreach($categories as $category)<option value="{{ $category }}" @selected(old('category', $project->category) === $category)>{{ $category }}</option>@endforeach</select></div>
        <div class="field"><label for="deadline">Preferred completion date</label><input type="date" id="deadline" name="deadline" @unless($project->exists) min="{{ now()->toDateString() }}" @endunless value="{{ old('deadline', $project->deadline?->format('Y-m-d')) }}"><p class="hint">A preference for volunteers to consider, rather than a guaranteed deadline.</p></div>
    </div>
    <div class="field"><label for="technologies">Technologies</label><input id="technologies" name="technologies" value="{{ old('technologies', implode(', ', $project->technologies ?? [])) }}" placeholder="Laravel, React, MySQL, Figma"><p class="hint">Separate up to 20 technologies with commas.</p></div>
    <div class="field"><label for="feedback_needed">What would you like reviewed? <span class="muted">*</span></label><textarea id="feedback_needed" name="feedback_needed" required maxlength="3000" rows="4" placeholder="e.g. Chapter 3 methodology, database structure, or usability of the prototype.">{{ old('feedback_needed', $project->feedback_needed) }}</textarea></div>
    <h2 class="project-form-section-heading">Your thesis group</h2>
    <div class="field"><label for="member_emails">Group members’ email addresses <span class="muted">(optional)</span></label><textarea id="member_emails" name="member_emails" rows="2" aria-describedby="member-emails-help" placeholder="classmate@example.com, teammate@example.com">{{ old('member_emails', $memberEmails) }}</textarea><p class="hint" id="member-emails-help">Use the email addresses your classmates registered with. Separate them with commas, semicolons, or new lines. You are already included. @if($project->exists)Keep existing members listed; an admin can help with member removal.@else Leave this blank to submit on your own. You can add registered classmates later.@endif Group members can access files, edit the project, and join conversations.</p>@error('member_emails')<p class="hint project-field-error" id="project-error-member_emails" data-project-field-error="member_emails">{{ $message }}</p>@enderror</div>
    @unless($project->exists)
        <h2 class="project-form-section-heading">Your thesis PDF</h2>
        <div class="field"><label for="document">Thesis PDF <span class="muted">*</span></label><input type="file" name="document" id="document" accept=".pdf,application/pdf" required><p class="hint">PDF only, up to 20 MB. Choose who can access your thesis PDF below.</p></div>
    @else
        <p class="hint">To add a revised PDF, use “Upload a revision” on the project page. Previous versions stay available.</p>
    @endunless
    <fieldset class="field project-pdf-access project-review-mode" aria-describedby="review-mode-help @error('review_mode') project-error-review_mode @enderror">
        <legend>How can evaluators review your thesis?</legend>
        <p class="project-pdf-access-help" id="review-mode-help">Choose whether to invite evaluators yourself or let approved evaluators start a review.</p>
        <div class="project-pdf-access-options">
            <label class="project-pdf-access-option" for="review-mode-request-only">
                <input type="radio" name="review_mode" id="review-mode-request-only" value="request_only" required @checked(old('review_mode', $project->review_mode ?? 'request_only') === 'request_only') aria-describedby="review-mode-request-help @error('review_mode') project-error-review_mode @enderror" @error('review_mode') aria-invalid="true" @enderror>
                <span><strong>By request only</strong><span id="review-mode-request-help">Choose an evaluator and send a review request. They can access their assigned PDF after accepting.</span></span>
            </label>
            <label class="project-pdf-access-option" for="review-mode-volunteer">
                <input type="radio" name="review_mode" id="review-mode-volunteer" value="volunteer" required @checked(old('review_mode', $project->review_mode ?? 'request_only') === 'volunteer') aria-describedby="review-mode-volunteer-help @error('review_mode') project-error-review_mode @enderror" @error('review_mode') aria-invalid="true" @enderror>
                <span><strong>Open to volunteer evaluators</strong><span id="review-mode-volunteer-help">Approved, active evaluators can start reviewing your latest PDF until the reviewer limit is reached. Each volunteer becomes an assigned evaluator.</span></span>
            </label>
        </div>
        @error('review_mode')<p class="hint project-field-error" id="project-error-review_mode" data-project-field-error="review_mode">{{ $message }}</p>@enderror
    </fieldset>
    <div class="field project-review-limit" data-review-limit>
        <label for="reviewer_limit">Maximum volunteer evaluators</label>
        <select name="reviewer_limit" id="reviewer_limit" aria-describedby="reviewer-limit-help @error('reviewer_limit') project-error-reviewer_limit @enderror" @error('reviewer_limit') aria-invalid="true" @enderror>
            @foreach(range(1, 5) as $limit)<option value="{{ $limit }}" @selected((string) old('reviewer_limit', $project->reviewer_limit ?? 1) === (string) $limit)>{{ $limit }} {{ Str::plural('evaluator', $limit) }}</option>@endforeach
        </select>
        <p class="hint" id="reviewer-limit-help">Used for volunteer reviews. Ongoing and completed reviews fill these slots. Drafts stay unpublished; volunteers can join only when your submitted project summary is shared publicly. Changing this setting keeps existing assignments.</p>
        @error('reviewer_limit')<p class="hint project-field-error" id="project-error-reviewer_limit" data-project-field-error="reviewer_limit">{{ $message }}</p>@enderror
    </div>
    <fieldset class="field project-pdf-access" aria-describedby="pdf-access-help pdf-access-private @error('pdf_access') project-error-pdf_access @enderror">
        <legend>Who can access your thesis PDF?</legend>
        <p class="project-pdf-access-help" id="pdf-access-help">Choose an audience for your original thesis PDF and its versions.</p>
        <div class="project-pdf-access-options">
            <label class="project-pdf-access-option" for="pdf-access-assigned">
                <input type="radio" name="pdf_access" id="pdf-access-assigned" value="assigned" required @checked(old('pdf_access', $project->pdf_access ?? 'assigned') === 'assigned') aria-describedby="pdf-access-assigned-help pdf-access-private @error('pdf_access') project-error-pdf_access @enderror" @error('pdf_access') aria-invalid="true" @enderror>
                <span><strong>Assigned evaluators only</strong><span id="pdf-access-assigned-help">Your thesis group and authorized admins can access all versions. An assigned evaluator can access only the PDF version attached to an accepted or completed review.</span></span>
            </label>
            <label class="project-pdf-access-option" for="pdf-access-approved-evaluators">
                <input type="radio" name="pdf_access" id="pdf-access-approved-evaluators" value="approved_evaluators" required @checked(old('pdf_access', $project->pdf_access ?? 'assigned') === 'approved_evaluators') aria-describedby="pdf-access-approved-help pdf-access-private @error('pdf_access') project-error-pdf_access @enderror" @error('pdf_access') aria-invalid="true" @enderror>
                <span><strong>All approved evaluators</strong><span id="pdf-access-approved-help">Approved, active evaluators can view and download every thesis PDF version before assignment while your summary is shared and the project is ready for review or completed.</span></span>
            </label>
        </div>
        <p class="project-pdf-access-help" id="pdf-access-private">Review summaries, feedback, and annotated review PDFs always stay private.</p>
        @error('pdf_access')<p class="hint project-field-error" id="project-error-pdf_access" data-project-field-error="pdf_access">{{ $message }}</p>@enderror
    </fieldset>
    <div class="field project-summary-sharing"><input type="hidden" name="is_public" value="0"><label><input type="checkbox" name="is_public" value="1" @checked(old('is_public', $project->exists ? $project->is_public : true))><span>Show my project summary in community projects.</span></label><p class="hint">Your summary appears in community projects when you submit. Uncheck this to keep the summary private. Drafts stay unpublished. PDF access is controlled separately by your choice above.</p></div>
    <div class="actions project-form-actions"><button class="btn btn-primary" type="submit">{{ $project->exists ? 'Save project' : 'Create project' }} <x-icon name="arrow-right"/></button>@if(! $project->exists || $project->status === 'draft')<button class="btn" type="submit" name="save_draft" value="1">Save draft</button>@endif<a href="{{ $project->exists ? route('account.projects.show', $project) : route('account.projects') }}" class="btn btn-ghost">Cancel</a></div>
    <p class="hint" data-project-submit-status role="status" aria-live="polite"></p>
</form>
<aside class="project-form-aside" aria-label="Project guidance">
    <section class="panel project-form-card project-form-guidance" aria-labelledby="project-guidance-title">
        <h2 id="project-guidance-title">A little guidance.</h2>
        <p>A clear summary and a focused review request help volunteers understand where they can contribute.</p>
        <div class="project-form-tip"><h3>Keep it focused.</h3><p>Ask for feedback on a chapter, design decision, or specific question.</p></div>
        <div class="project-form-tip"><h3>Be considerate.</h3><p>Our evaluators volunteer their time. They will confirm a date they can accommodate.</p></div>
        <div class="project-form-tip"><h3>Leave out sensitive data.</h3><p>Remove personal research participant details before sharing your document.</p></div>
    </section>
    <section class="panel project-form-card project-form-guidance" aria-labelledby="project-ready-title"><span class="project-form-note-icon"><x-icon name="shield-check"/></span><h2 id="project-ready-title">Ready for a fresh perspective.</h2><p>New projects are ready for review as soon as you submit. Choose whether to share your summary, or save a draft to finish later.</p></section>
</aside>
</div>
@endsection
