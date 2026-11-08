@extends('layouts.app')
@section('title', 'Evaluator verification')
@section('body-class', 'projects-page verification-page')
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/projects.css') }}?v={{ filemtime(public_path('assets/projects.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/verification.css') }}?v={{ filemtime(public_path('assets/verification.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/evaluator-application-resume.css') }}?v={{ filemtime(public_path('assets/evaluator-application-resume.css')) }}">
@endpush
@section('content')
@php
    $backgroundType = old('background_type', $verification?->background_type ?? 'employed');
    $evidenceType = old('evidence_type', $verification?->evidence_type ?? 'document');
    $needsModerationUpdate = $verification?->status === 'approved' && $profileCurrent && $user->evaluator_status !== 'approved';
    $underReview = $verification?->status === 'pending' && $profileCurrent;
@endphp
@include('projects.partials.sky')
<a class="projects-back page-back verification-back" href="{{ route('account.profile') }}"><x-icon name="arrow-left"/>Back to profile</a>
<header class="projects-header verification-header">
    <h1>A little <em>proof.</em></h1>
    <p>Help the admin team understand your experience and how you give constructive feedback.</p>
</header>

<div class="split verification-layout"><div class="stack verification-main">
    <section class="panel stack" id="application-status" aria-label="Application status">
        <h2>Your application</h2>
        @if($verification)
            <div class="actions"><x-status :status="$user->evaluator_status" /><span class="small muted">Latest submission {{ $verification->created_at->format('M j, Y, g:i a') }}</span></div>
            @if($needsModerationUpdate)
                <p class="small">Your evaluator profile needs another review. Read the current admin note, make the requested updates, and submit evidence again below. Your earlier approval remains in your private history.</p>
                @if($user->moderation_note)<div class="verification-note"><strong class="small">Current admin note</strong><p class="preserve small">{{ $user->moderation_note }}</p></div>@endif
            @elseif(!$profileCurrent)
                <p class="small">Your professional profile has changed since this submission. Update your details and submit new evidence below so the admin can review the current information.</p>
            @elseif($underReview)
                <div class="stack verification-pending" role="status">
                    <div class="verification-pending-heading">
                        <x-icon name="shield-check" />
                        <h2>Your application is under review</h2>
                    </div>
                    <p>Your professional details, evidence, and practice review have been saved.</p>
                    <div class="verification-note"><p class="small">Estimated review time: 24–48 hours. Reviews may take longer depending on volunteer availability.</p></div>
                    <p class="small muted">Please wait for the admin's decision. You do not need to upload your proof again. Your evaluator profile stays hidden until approval.</p>
                </div>
                <a class="text-link" href="{{ route('account.dashboard', ['tab' => 'notifications']) }}"><x-icon name="bell" />Check Notifications for the decision <x-icon name="arrow-right" /></a>
                <a class="text-link small" href="#submission-history">View your submitted application <x-icon name="arrow-right" /></a>
            @elseif($verification->status === 'approved')
                <p class="small muted">Your professional evidence and practice review have been reviewed. You can update your professional details and evidence below, or manage availability on My profile.</p>
            @else
                <p class="small muted">Read the admin's feedback, make the requested updates, and submit a new application. Your earlier submissions remain in your private history.</p>
            @endif
            @if($verification->review_notes)<div class="verification-note"><strong class="small">Admin feedback</strong><p class="preserve small">{{ $verification->review_notes }}</p></div>@endif
        @else
            <p class="small muted">Upload your professional evidence below. You can complete any missing professional details in the same application.</p>
        @endif
        @if(!$profileComplete)
            <p class="hint">Still needed: {{ implode(', ', $missingProfileFields ?? []) }}. You can fill these in below while uploading your proof.</p>
        @endif
        @if($errors->any() && $canSubmit)
            <div class="verification-note"><strong class="small">Your application has not been submitted yet.</strong><p class="hint">Your text entries are kept below. If you selected supporting documents, choose those files again before submitting.</p></div>
        @endif
        @if($canSubmit)<a class="btn btn-primary" href="#supporting-evidence">Upload professional proof <x-icon name="arrow-right" /></a>@endif
    </section>

    @if($canSubmit)
    @if($verification?->status === 'approved' && $profileCurrent && !$needsModerationUpdate)<details class="panel" id="update-verification" open><summary>Update professional details and proof</summary><p class="small muted">Submitting updated professional details or proof returns your profile to pending review and pauses new review requests until approval.</p>@endif
    <form method="POST" action="{{ route('account.evaluator-verification.store') }}" enctype="multipart/form-data" class="panel stack verification-form" id="submit-evidence">
        @csrf
        <div id="supporting-evidence"><h2 class="verification-section-title">Supporting evidence</h2><p class="small muted">Choose one way for the admin to check your professional background.</p></div>
        <fieldset class="field"><legend>Evidence option</legend><div class="verification-options">
            <label><input type="radio" name="evidence_type" value="document" @checked($evidenceType === 'document') data-evidence-kind> Professional document</label>
            <label><input type="radio" name="evidence_type" value="portfolio" @checked($evidenceType === 'portfolio') data-evidence-kind> Portfolio / professional profile</label>
            <label><input type="radio" name="evidence_type" value="reference" @checked($evidenceType === 'reference') data-evidence-kind> Professional reference</label>
        </div></fieldset>
        <div class="stack evidence-fields" data-evidence-fields="document">
            <div class="field"><label for="evidence_files">Supporting documents</label><input id="evidence_files" name="evidence_files[]" type="file" multiple accept=".pdf,.jpg,.jpeg,.png" data-evidence-required><span class="hint">1–3 files, PDF / JPG / PNG, up to 5 MB each. For example, a redacted employment or teaching document, a certificate with a verification reference, or a project contribution record.</span></div>
            <p class="hint">Redact addresses, salary, signatures, ID numbers, and other unnecessary personal details. Government IDs are not required. Each new submission needs its own files.</p>
        </div>
        <div class="stack evidence-fields" data-evidence-fields="portfolio">
            <div class="field"><label for="evidence_url">Professional evidence link</label><input id="evidence_url" name="evidence_url" type="url" maxlength="500" value="{{ old('evidence_url', $verification?->evidence_url) }}" placeholder="https://your-portfolio.example" data-evidence-required><span class="hint">A portfolio, contribution history, organization profile, or another relevant public record.</span></div>
        </div>
        <div class="form-grid evidence-fields" data-evidence-fields="reference">
            <div class="field"><label for="reference_name">Reference's full name</label><input id="reference_name" name="reference_name" maxlength="120" value="{{ old('reference_name', $verification?->reference_name) }}" data-evidence-required></div>
            <div class="field"><label for="reference_email">Reference's professional email</label><input id="reference_email" name="reference_email" type="email" maxlength="255" value="{{ old('reference_email', $verification?->reference_email) }}" data-evidence-required></div>
            <div class="field full"><label for="reference_relationship">Their role and relationship to you</label><input id="reference_relationship" name="reference_relationship" maxlength="300" value="{{ old('reference_relationship', $verification?->reference_relationship) }}" placeholder="e.g. Team lead who supervised my database work" data-evidence-required><span class="hint">Obtain permission before sharing their details. Providing an email is not a completed reference check; the admin must record how they verified it.</span></div>
        </div>
        <div class="field evidence-fields" data-evidence-fields="portfolio reference"><label for="evidence_explanation">How can the admin verify this evidence?</label><textarea id="evidence_explanation" name="evidence_explanation" rows="4" minlength="50" maxlength="3000" data-evidence-required placeholder="Explain how the evidence connects to you, your role, and your stated expertise.">{{ old('evidence_explanation', $verification?->evidence_explanation) }}</textarea><span class="hint">At least 50 characters. For a portfolio, explain your contribution and how ownership can be checked. For a reference, describe what they can confirm.</span></div>

        <div><h2 class="verification-section-title">Professional background</h2><p class="small muted">Explain your experience in the areas you want to review.</p></div>
        @include('account.partials.verification-profile-fields')
        <div class="form-grid">
            <div class="field"><label for="background_type">Your background</label><select id="background_type" name="background_type" required>
                <option value="employed" @selected($backgroundType === 'employed')>Employed IT professional</option>
                <option value="freelance" @selected($backgroundType === 'freelance')>Freelancer / independent professional</option>
                <option value="academic" @selected($backgroundType === 'academic')>IT educator / researcher</option>
            </select></div>
            <div class="field"><label for="organization">Organization or practice <span class="hint">Optional</span></label><input id="organization" name="organization" maxlength="180" value="{{ old('organization', $verification?->organization) }}" placeholder="Employer, school, or independent practice"></div>
            <div class="field full"><label for="background_summary">Relevant experience</label><textarea id="background_summary" name="background_summary" rows="5" minlength="80" maxlength="3000" required placeholder="Describe your role, relevant projects, and the thesis topics you can review.">{{ old('background_summary', $verification?->background_summary) }}</textarea><span class="hint">At least 80 characters. Keep client secrets and confidential research out of your response.</span></div>
        </div>

        <div><h2 class="verification-section-title">A relevant work sample</h2><p class="small muted">Help the admin assess your practical experience.</p></div>
        <div class="field"><label for="work_sample_url">Work sample link</label><input id="work_sample_url" name="work_sample_url" type="url" maxlength="500" required value="{{ old('work_sample_url', $verification?->work_sample_url) }}" placeholder="https://github.com/your-name/your-project"><span class="hint">A repository, application, publication, teaching resource, or project case study you are permitted to share. Do not include passwords or private access tokens.</span></div>
        <div class="field"><label for="work_sample_explanation">What did you contribute?</label><textarea id="work_sample_explanation" name="work_sample_explanation" rows="4" minlength="50" maxlength="3000" required>{{ old('work_sample_explanation', $verification?->work_sample_explanation) }}</textarea><span class="hint">At least 50 characters. Point to specific work and connect it to your expertise.</span></div>

        <div><h2 class="verification-section-title">A short practice review</h2><p class="small muted">Use this fictional excerpt to show how you would guide a student.</p></div>
        <blockquote class="verification-prompt"><p>{{ \App\Models\EvaluatorVerification::SAMPLE_PROMPT }}</p><span class="hint">Practice material created for this application.</span></blockquote>
        <div class="field"><label for="sample_review">Your recommendations</label><textarea id="sample_review" name="sample_review" rows="8" minlength="150" maxlength="6000" required placeholder="Give 2–3 specific recommendations. Explain the concern, why it matters, and a practical next step.">{{ old('sample_review', $verification?->sample_review) }}</textarea><span class="hint">At least 150 characters. The admin checks accuracy, clarity, usefulness, and respectful feedback.</span></div>
        <label class="small verification-attestation"><input type="checkbox" name="attestation" value="1" @checked(old('attestation')) required><span>I confirm that my information is accurate, the work described is my own contribution, and I have permission to share this evidence and any reference details.</span></label>
        <p class="hint">Submitted applications are retained as a private review history. Material profile changes or replacement evidence require another admin review.</p>
        <button class="btn btn-primary" type="submit">Submit for admin verification <x-icon name="arrow-right" /></button>
    </form>
    @if($verification?->status === 'approved' && $profileCurrent && !$needsModerationUpdate)</details>@endif
    @endif

    @if($history->isNotEmpty())
    <section class="section-block" id="submission-history"><div class="section-heading"><h2>Your submission history</h2><span class="mono small muted">PRIVATE</span></div>
        @foreach($history as $submission)
        <details class="panel verification-history" @if($loop->first && !$canSubmit) open @endif>
            <summary><span>Submitted {{ $submission->created_at->format('M j, Y') }}</span>@if($submission->status === 'pending' && $submission->id !== $verification?->id)<span class="status">Replaced by a newer submission</span>@else<x-status :status="$submission->status" />@endif</summary>
            <div class="verification-history-content">
                <x-evaluator-application-resume :submission="$submission" :applicant="$user" />
                @if($submission->reviewed_at)<div class="verification-note"><p class="hint">Reviewed {{ $submission->reviewed_at->format('M j, Y, g:i a') }} by {{ $submission->reviewer?->name ?? 'Admin' }}</p><p class="preserve small">{{ $submission->review_notes }}</p></div>@endif
            </div>
        </details>
        @endforeach
        <x-pagination :paginator="$history" />
    </section>
    @endif
</div><aside class="aside stack verification-sidebar">
    <div class="panel"><x-icon name="shield-check" /><h3>Reviewed by a person.</h3><ol class="verification-steps"><li>Upload professional evidence.</li><li>Complete your details, a work sample, and a practice review in this application.</li><li>An admin checks your background, expertise, evidence, and feedback.</li><li>Receive approval or guidance on what is missing.</li></ol></div>
    <div class="panel"><x-icon name="lock-keyhole" /><h3>Your evidence stays private.</h3><p class="small muted">Only you and authorized admins can access these submissions and files. Students see your approved public profile, never your evidence or reference details.</p><p class="hint">“Admin-reviewed evaluator” means the submitted background and practice feedback were reviewed for community participation. It is not a professional license or academic endorsement.</p></div>
    @if($user->isEvaluator())<a class="text-link small" href="{{ route('account.profile') }}#review-availability">Manage review availability <x-icon name="arrow-up-right" /></a>@endif
</aside></div>
@endsection
