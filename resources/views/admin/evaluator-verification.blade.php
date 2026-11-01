@extends('layouts.app')
@section('title', 'Review evaluator application')
@section('body-class', 'projects-page admin-page admin-verification-page')
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/projects.css') }}?v={{ filemtime(public_path('assets/projects.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/admin.css') }}?v={{ filemtime(public_path('assets/admin.css')) }}">
@endpush
@section('content')
@php
    $safeUrl = fn ($url) => is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
        && in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true);
    $snapshot = $verification?->profile_snapshot ?? [];
    $sameReviewInput = $verification && (string) old('verification_id') === (string) $verification->id;
    $backgroundLabels = ['employed' => 'Employed professional', 'freelance' => 'Freelancer / independent professional', 'academic' => 'Academic IT professional'];
    $evidenceLabels = ['document' => 'Private supporting document', 'portfolio' => 'Professional portfolio', 'reference' => 'Professional reference'];
@endphp
@include('projects.partials.sky')
<div class="admin-layout">
    @include('admin.partials.section-nav', ['section' => 'evaluators', 'stats' => []])
<div class="admin-main">
<a class="projects-back page-back admin-back" href="{{ route('admin.index', ['section' => 'evaluators']) }}"><x-icon name="arrow-left"/>Back to evaluator applications</a>
<header class="projects-header admin-page-header">
    <h1>Review an <em>evaluator.</em></h1>
    <p>{{ $evaluator->name }} · {{ $evaluator->email }}</p>
</header>
<div class="stack admin-verification-content">
<div class="panel list-item admin-account-status">
    <div><span class="section-label">ACCOUNT STATUS</span><div class="actions"><x-status :status="$evaluator->evaluator_status" />@if($evaluator->is_suspended)<span class="badge">Account suspended</span>@endif</div></div>
    @if($verification)<div><span class="section-label">LATEST SUBMISSION</span><p class="small">#{{ $verification->id }} · {{ $verification->created_at->format('M j, Y · g:i a') }}</p></div>@endif
    @if($evaluator->moderation_note)<div class="admin-account-note"><span class="section-label">CURRENT ADMIN NOTE</span><p class="small">{{ $evaluator->moderation_note }}</p></div>@endif
</div>

@unless($verification)
    <x-empty title="The professional application has not been submitted." text="This applicant must submit their professional background, supporting evidence, a work sample, and sample feedback before you can review or approve them." />
    <div class="panel stack"><div class="section-label">CURRENT SELF-REPORTED PROFILE</div><h3>{{ $evaluator->headline ?: 'Headline not completed' }}</h3><p class="small">{{ $evaluator->bio ?: 'Biography not completed.' }}</p><x-tags :tags="$evaluator->expertise ?? []" /><p class="hint">The current profile alone does not establish professional evidence.</p></div>
@else
    @unless($profileMatches)
        <div class="alert" role="status"><div><strong>The professional profile has changed.</strong><p class="small">This submission records the earlier profile below. Request an updated application before approval.</p></div></div>
    @endunless
    @unless($complete)
        <div class="alert" role="status"><div><strong>Supporting information is incomplete or unavailable.</strong><p class="small">An application must include complete professional evidence, a work sample, sample feedback, and an attestation before approval.</p></div></div>
    @endunless
    @if($evaluator->is_suspended)<div class="alert"><div><strong>This account is suspended.</strong><p class="small">Reinstate the account from user management before considering approval.</p></div></div>@endif

    <section class="panel stack">
        <div class="section-label">01 / PROFILE AS SUBMITTED</div>
        <div class="list-item"><div><h2>{{ $snapshot['name'] ?? $evaluator->name }}</h2><p class="muted">{{ $snapshot['headline'] ?? 'Not provided' }}</p></div><x-status :status="$verification->status" /></div>
        <p class="small" style="white-space: pre-wrap">{{ $snapshot['bio'] ?? 'Not provided' }}</p>
        <div class="form-grid"><div><span class="section-label">EXPERTISE</span><x-tags :tags="$snapshot['expertise'] ?? []" /></div><div><span class="section-label">SKILLS</span><x-tags :tags="$snapshot['skills'] ?? []" /></div></div>
        <p class="small"><strong>Experience:</strong> {{ $snapshot['experience_years'] ?? 0 }} years · <strong>Review modes:</strong> {{ implode(', ', $snapshot['review_modes'] ?? []) ?: 'Not provided' }}</p>
        @if($safeUrl($snapshot['portfolio_url'] ?? null))<p class="small"><a href="{{ $snapshot['portfolio_url'] }}" target="_blank" rel="noopener noreferrer nofollow">Open public LinkedIn / portfolio link ↗</a></p>@endif
        <span class="hint">This snapshot records the professional details you are assessing.</span>
    </section>

    <section class="panel stack">
        <div class="section-label">02 / PROFESSIONAL BACKGROUND & PRIVATE EVIDENCE</div>
        <h2>{{ $backgroundLabels[$verification->background_type] ?? 'Professional background' }}</h2>
        @if($verification->organization)<p class="small"><strong>Organization / practice:</strong> {{ $verification->organization }}</p>@endif
        <p class="small" style="white-space: pre-wrap">{{ $verification->background_summary }}</p>
        <h3>{{ $evidenceLabels[$verification->evidence_type] ?? 'Supporting evidence' }}</h3>
        @if($verification->evidence_explanation)<p class="small" style="white-space: pre-wrap">{{ $verification->evidence_explanation }}</p>@endif
        @if($verification->evidence_type === 'document')
            @forelse($verification->evidence as $evidence)
                <div class="list-item"><div><strong>{{ $evidence->original_name }}</strong><p class="hint">{{ $evidence->mime_type }} · {{ number_format($evidence->size / 1024) }} KB · Private attachment</p></div><a class="btn btn-small" href="{{ route('account.evaluator-evidence.download', ['evidence' => $evidence]) }}">Download evidence <x-icon name="download" /></a></div>
            @empty<p class="small">No supporting document is available for this submission.</p>@endforelse
        @elseif($verification->evidence_type === 'portfolio')
            @if($safeUrl($verification->evidence_url))<p class="small"><a href="{{ $verification->evidence_url }}" target="_blank" rel="noopener noreferrer nofollow">Open submitted professional evidence ↗</a></p>@else<p class="small">A valid professional evidence link has not been supplied.</p>@endif
        @elseif($verification->evidence_type === 'reference')
            <div class="form-grid"><div><span class="section-label">REFERENCE</span><p class="small">{{ $verification->reference_name }}</p></div><div><span class="section-label">REFERENCE EMAIL</span><p class="small" style="overflow-wrap:anywhere">{{ $verification->reference_email }}</p></div><div class="full"><span class="section-label">PROFESSIONAL RELATIONSHIP</span><p class="small">{{ $verification->reference_relationship }}</p></div></div>
            <span class="hint">Check that the reference can confirm the claimed background. This contact information stays private.</span>
        @endif
        <p class="hint">Application evidence and reference details are accessible only to the applicant and administrators. Links open when you select them.</p>
    </section>

    <section class="panel stack">
        <div class="section-label">03 / WORK SAMPLE</div>
        @if($safeUrl($verification->work_sample_url))<p class="small"><a href="{{ $verification->work_sample_url }}" target="_blank" rel="noopener noreferrer nofollow">Open work sample ↗</a></p>@else<p class="small">A valid work sample link has not been supplied.</p>@endif
        <p class="small" style="white-space: pre-wrap">{{ $verification->work_sample_explanation }}</p>
        <span class="hint">Assess the applicant’s own contribution, how it relates to their expertise, and whether the work supports their stated experience.</span>
    </section>

    <section class="panel stack">
        <div class="section-label">04 / SAMPLE FEEDBACK ASSESSMENT</div>
        <details><summary class="mono small">Read the sample thesis prompt</summary><p class="small" style="white-space: pre-wrap">{{ \App\Models\EvaluatorVerification::SAMPLE_PROMPT }}</p></details>
        <h3>The applicant’s recommendations</h3>
        <p class="small" style="white-space: pre-wrap">{{ $verification->sample_review }}</p>
        <p class="hint">Look for a clear concern, a relevant explanation, and practical improvements. Evaluate whether the feedback respects the limits of the sample.</p>
        <p class="small"><strong>Accuracy and permission attestation:</strong> {{ $verification->attestation ? 'Confirmed by the applicant' : 'Not confirmed' }}</p>
    </section>

    @if($canReview)
        <section class="panel stack" id="review-decision">
            <div class="section-label">05 / ADMIN REVIEW RECORD</div>
            <h2>Record what you checked.</h2>
            <p class="muted small">Complete every check before approval. Your decision note is required and will be shared with the applicant.</p>
            <form method="POST" action="{{ route('admin.evaluators.moderate', $evaluator) }}" class="stack">
                @csrf
                <input type="hidden" name="verification_id" value="{{ $verification->id }}">
                <fieldset class="field"><legend>Verification checklist</legend><div class="stack">
                    @foreach($reviewChecks as $key => $label)
                        <label class="small"><input type="checkbox" name="checks[{{ $key }}]" value="1" @checked($sameReviewInput && old('checks.'.$key))> {{ $label }}</label>
                    @endforeach
                </div></fieldset>
                <div class="field"><label for="review-reason">Review notes and decision reason</label><textarea id="review-reason" name="reason" rows="5" maxlength="2000" required placeholder="Describe the evidence you checked, the quality of the sample feedback, and your decision. For more information requests, explain exactly what the applicant needs to provide.">{{ $sameReviewInput ? old('reason') : '' }}</textarea><span class="hint">These notes are shared with the applicant. Record how you checked the evidence and give clear next steps.</span></div>
                <div class="actions"><button class="btn btn-primary" type="submit" name="decision" value="approve" @disabled(! $canApprove)>Approve evaluator</button><button class="btn" type="submit" name="decision" value="needs_info">Request more information</button><button class="btn btn-danger" type="submit" name="decision" value="reject">Reject application</button></div>
                @unless($canApprove)<span class="hint">Approval is unavailable until the application is complete, matches the current professional profile, and the account is active.</span>@endunless
            </form>
        </section>
    @else
        <div class="panel"><p class="small">This submission has already received a decision. An updated application is required before another verification review.</p>@if($verification->review_notes)<p class="small"><strong>Decision note:</strong> {{ $verification->review_notes }}</p>@endif</div>
    @endif

    <section class="stack">
        <div class="section-label">06 / APPLICATION & DECISION HISTORY</div>
        @foreach($verifications as $previous)
            <details class="panel"><summary class="mono small">Submission #{{ $previous->id }} · {{ $previous->created_at->format('M j, Y') }} · {{ $previous->status === 'pending' && $previous->id !== $verification->id ? 'Replaced by a newer submission' : ucfirst(str_replace('_', ' ', $previous->status)) }}</summary>
                <div class="stack">
                    <p class="small"><strong>Reviewed by:</strong> {{ $previous->reviewer?->name ?? 'Not reviewed yet' }}@if($previous->reviewed_at) · {{ $previous->reviewed_at->format('M j, Y · g:i a') }}@endif</p>
                    @if($previous->review_notes)<p class="small" style="white-space:pre-wrap"><strong>Decision reason:</strong> {{ $previous->review_notes }}</p>@endif
                    @if($previous->checks)<div><span class="section-label">RECORDED CHECKS</span>@foreach($reviewChecks as $key => $label)<p class="small">{{ ! empty($previous->checks[$key]) ? '✓' : '—' }} {{ $label }}</p>@endforeach</div>@endif
                    @if($previous->id !== $verification->id)
                        <p class="small"><strong>Submitted professional headline:</strong> {{ $previous->profile_snapshot['headline'] ?? 'Not provided' }}</p>
                        <p class="small" style="white-space:pre-wrap"><strong>Background:</strong> {{ $previous->background_summary }}</p>
                        @if($safeUrl($previous->evidence_url))<p class="small"><a href="{{ $previous->evidence_url }}" target="_blank" rel="noopener noreferrer nofollow">Open historical evidence link ↗</a></p>@endif
                        @foreach($previous->evidence as $evidence)<a class="small" href="{{ route('account.evaluator-evidence.download', ['evidence' => $evidence]) }}">Download historical evidence: {{ $evidence->original_name }}</a>@endforeach
                        @if($previous->evidence_type === 'reference')<p class="small"><strong>Submitted reference:</strong> {{ $previous->reference_name }} · {{ $previous->reference_email }} · {{ $previous->reference_relationship }}</p>@endif
                        @if($safeUrl($previous->work_sample_url))<p class="small"><a href="{{ $previous->work_sample_url }}" target="_blank" rel="noopener noreferrer nofollow">Open historical work sample ↗</a></p>@endif
                        <p class="small" style="white-space:pre-wrap"><strong>Submitted sample feedback:</strong> {{ $previous->sample_review }}</p>
                    @endif
                </div>
            </details>
        @endforeach
    </section>
@endunless
</div>
</div>
</div>
@endsection
