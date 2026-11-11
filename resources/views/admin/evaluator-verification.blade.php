@extends('layouts.app')
@section('title', 'Review evaluator application')
@section('body-class', 'projects-page admin-page admin-verification-page')
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/projects.css') }}?v={{ filemtime(public_path('assets/projects.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/admin.css') }}?v={{ filemtime(public_path('assets/admin.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/admin-evaluator-application.css') }}?v={{ filemtime(public_path('assets/admin-evaluator-application.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/evaluator-application-resume.css') }}?v={{ filemtime(public_path('assets/evaluator-application-resume.css')) }}">
@endpush
@section('content')
@php
    $safeUrl = fn ($url) => is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
        && in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true);
    $sameReviewInput = $verification && (string) old('verification_id') === (string) $verification->id;
@endphp
@include('projects.partials.sky')
<div class="admin-layout">
    @include('admin.partials.section-nav', ['section' => 'evaluators', 'stats' => []])
<div class="admin-main">
<a class="projects-back page-back admin-back" href="{{ route('admin.index', ['section' => 'evaluators']) }}"><x-icon name="arrow-left"/>Back to evaluator applications</a>
<header class="projects-header admin-page-header">
    <h1>Review an <em>evaluator.</em></h1>
</header>
<div class="stack admin-verification-content">
<div class="admin-application-toolbar">
    <div class="admin-application-state">
        <div class="admin-application-status"><span class="small">Account status</span><x-status :status="$evaluator->evaluator_status" /></div>
        @if($verification)<div class="admin-application-status"><span class="small">Submission status</span><x-status :status="$verification->status" /></div>@endif
        @if($evaluator->is_suspended)<span class="badge">Account suspended</span>@endif
    </div>
    @if($verification)<p class="admin-application-meta small">Submitted <time datetime="{{ $verification->created_at->toIso8601String() }}">{{ $verification->created_at->format('M j, Y · g:i a') }}</time></p>@endif
</div>

@unless($verification)
    <x-empty title="The professional application has not been submitted." text="This applicant must submit their professional background, supporting evidence, a work sample, and sample feedback before you can review or approve them." />
    <section class="panel stack"><div class="section-label">Current self-reported profile</div><div><h2>{{ $evaluator->name }}</h2><p class="small">{{ $evaluator->email }}</p></div><h3>{{ $evaluator->headline ?: 'Headline not completed' }}</h3><p class="small">{{ $evaluator->bio ?: 'Biography not completed.' }}</p><x-tags :tags="$evaluator->expertise ?? []" /><p class="hint">The current profile alone does not establish professional evidence.</p></section>
@else
    @unless($profileMatches)
        <div class="alert" role="status"><div><strong>The professional profile has changed.</strong><p class="small">This submission records the earlier profile below. Request an updated application before approval.</p></div></div>
    @endunless
    @unless($complete)
        <div class="alert" role="status"><div><strong>Supporting information is incomplete or unavailable.</strong><p class="small">An application must include complete professional evidence, a work sample, sample feedback, and an attestation before approval.</p></div></div>
    @endunless
    @if($evaluator->is_suspended)<div class="alert"><div><strong>This account is suspended.</strong><p class="small">Reinstate the account from user management before considering approval.</p></div></div>@endif

    <x-evaluator-application-resume :submission="$verification" :applicant="$evaluator" :admin-view="true" />

    @if($canReview)
        <section class="panel stack admin-review-panel" id="review-decision" aria-labelledby="review-decision-heading">
            <div class="admin-review-heading"><h2 id="review-decision-heading">Admin review &amp; decision</h2><p class="muted small">Complete every check before approval. Your decision note is required and will be shared with the applicant.</p></div>
            <form method="POST" action="{{ route('admin.evaluators.moderate', $evaluator) }}" class="stack">
                @csrf
                <input type="hidden" name="verification_id" value="{{ $verification->id }}">
                <fieldset class="field admin-review-checklist"><legend>Verification checklist</legend><div class="stack">
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
        <section class="panel stack admin-review-panel" aria-labelledby="recorded-decision-heading"><h2 id="recorded-decision-heading">Recorded decision</h2><p class="small">This submission has already received a decision. An updated application is required before another verification review.</p>@if($verification->review_notes)<p class="small preserve"><strong>Decision note:</strong> {{ $verification->review_notes }}</p>@endif</section>
    @endif

    <section class="stack admin-review-history" aria-labelledby="application-history-heading">
        <h2 id="application-history-heading">Application &amp; decision history</h2>
        @foreach($verifications as $previous)
            <details class="panel"><summary>Submitted {{ $previous->created_at->format('M j, Y · g:i a') }} · {{ $previous->status === 'pending' && $previous->id !== $verification->id ? 'Replaced by a newer submission' : ucfirst(str_replace('_', ' ', $previous->status)) }}</summary>
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
