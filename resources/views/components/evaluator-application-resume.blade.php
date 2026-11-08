@props(['submission', 'applicant', 'adminView' => false])
@php
    $snapshot = $submission->profile_snapshot ?? [];
    $resumeId = 'application-resume-'.$submission->id;
    $safeUrl = fn ($url) => is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
        && in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true);
    $backgroundLabels = ['employed' => 'Employed professional', 'freelance' => 'Freelancer / independent professional', 'academic' => 'Academic IT professional'];
    $evidenceLabels = ['document' => 'Private supporting document', 'portfolio' => 'Professional portfolio', 'reference' => 'Professional reference'];
@endphp
<article {{ $attributes->class(['evaluator-application-resume']) }} aria-labelledby="{{ $resumeId }}-name">
    <header class="application-resume-header">
        <h2 class="application-resume-name" id="{{ $resumeId }}-name">{{ $snapshot['name'] ?? 'Name not provided' }}</h2>
        <p class="application-resume-headline">{{ $snapshot['headline'] ?? 'Professional headline not provided' }}</p>
        <div class="application-resume-contact">
            @if($applicant->email)<span>{{ $applicant->email }}</span>@endif
            @if($safeUrl($snapshot['portfolio_url'] ?? null))<a class="application-resume-link" href="{{ $snapshot['portfolio_url'] }}" target="_blank" rel="noopener noreferrer nofollow">LinkedIn / portfolio</a>@endif
        </div>
        <dl class="application-resume-facts">
            <div><dt>Experience</dt><dd>{{ ($snapshot['experience_years'] ?? 0) > 0 ? $snapshot['experience_years'].' '.\Illuminate\Support\Str::plural('year', $snapshot['experience_years']) : 'Not provided' }}</dd></div>
            <div><dt>Review mode</dt><dd>{{ implode(', ', array_map(fn ($mode) => $mode === 'on-site' ? 'On-site' : ucfirst($mode), $snapshot['review_modes'] ?? [])) ?: 'Not provided' }}</dd></div>
        </dl>
    </header>

    <section class="application-resume-section" aria-labelledby="{{ $resumeId }}-summary">
        <h3 class="application-resume-section-title" id="{{ $resumeId }}-summary">Professional summary</h3>
        <p class="application-resume-copy">{{ $snapshot['bio'] ?? 'Not provided' }}</p>
    </section>

    <section class="application-resume-section" aria-labelledby="{{ $resumeId }}-skills">
        <h3 class="application-resume-section-title" id="{{ $resumeId }}-skills">Expertise &amp; skills</h3>
        <dl class="application-resume-skills">
            <div><dt>Areas of expertise</dt><dd>{{ implode(', ', $snapshot['expertise'] ?? []) ?: 'Not provided' }}</dd></div>
            <div><dt>Technical skills</dt><dd>{{ implode(', ', $snapshot['skills'] ?? []) ?: 'Not provided' }}</dd></div>
        </dl>
    </section>

    <section class="application-resume-section" aria-labelledby="{{ $resumeId }}-background">
        <h3 class="application-resume-section-title" id="{{ $resumeId }}-background">Professional background</h3>
        <h4 class="application-resume-subheading">{{ $backgroundLabels[$submission->background_type] ?? 'Professional background' }}</h4>
        @if($submission->organization)<p class="application-resume-copy"><strong>Organization / practice:</strong> {{ $submission->organization }}</p>@endif
        <p class="application-resume-copy">{{ $submission->background_summary }}</p>
    </section>

    <section class="application-resume-section" aria-labelledby="{{ $resumeId }}-evidence">
        <h3 class="application-resume-section-title" id="{{ $resumeId }}-evidence">Supporting evidence</h3>
        <h4 class="application-resume-subheading">{{ $evidenceLabels[$submission->evidence_type] ?? 'Supporting evidence' }}</h4>
        @if($submission->evidence_explanation)<p class="application-resume-copy">{{ $submission->evidence_explanation }}</p>@endif
        @if($submission->evidence_type === 'document')
            <ul class="application-resume-evidence">
                @forelse($submission->evidence as $evidence)
                    <li>
                        <div class="application-resume-file-info"><strong>{{ $evidence->original_name }}</strong><p class="hint">{{ $evidence->mime_type }} &middot; {{ number_format($evidence->size / 1024) }} KB &middot; Private attachment</p></div>
                        <a class="btn btn-small" href="{{ route('account.evaluator-evidence.download', ['evidence' => $evidence]) }}">Download evidence <x-icon name="download" /></a>
                    </li>
                @empty
                    <li><p class="small">No supporting document is available for this submission.</p></li>
                @endforelse
            </ul>
        @elseif($submission->evidence_type === 'portfolio')
            @if($safeUrl($submission->evidence_url))<a class="application-resume-link" href="{{ $submission->evidence_url }}" target="_blank" rel="noopener noreferrer nofollow">Open submitted professional evidence <x-icon name="arrow-up-right" /></a>@else<p class="small">A valid professional evidence link has not been supplied.</p>@endif
        @elseif($submission->evidence_type === 'reference')
            <dl class="application-resume-reference">
                <div><dt>Reference</dt><dd>{{ $submission->reference_name }}</dd></div>
                <div><dt>Reference email</dt><dd>{{ $submission->reference_email }}</dd></div>
                <div><dt>Professional relationship</dt><dd>{{ $submission->reference_relationship }}</dd></div>
            </dl>
            @if($adminView)<p class="hint">Check that the reference can confirm the claimed background. This contact information stays private.</p>@endif
        @endif
        <p class="hint">{{ $adminView ? 'Application evidence and reference details are accessible only to the applicant and administrators. Links open when you select them.' : 'Your application evidence and reference details are private. Only you and authorized administrators can access them. Links open when you select them.' }}</p>
    </section>

    <section class="application-resume-section" aria-labelledby="{{ $resumeId }}-work">
        <h3 class="application-resume-section-title" id="{{ $resumeId }}-work">Work sample</h3>
        @if($safeUrl($submission->work_sample_url))<a class="application-resume-link" href="{{ $submission->work_sample_url }}" target="_blank" rel="noopener noreferrer nofollow">Open work sample <x-icon name="arrow-up-right" /></a>@else<p class="small">A valid work sample link has not been supplied.</p>@endif
        <p class="application-resume-copy">{{ $submission->work_sample_explanation }}</p>
        @if($adminView)<p class="hint">Assess the applicant's own contribution, its relevance to their expertise, and whether it supports their stated experience.</p>@endif
    </section>

    <section class="application-resume-section" aria-labelledby="{{ $resumeId }}-feedback">
        <h3 class="application-resume-section-title" id="{{ $resumeId }}-feedback">Practice review</h3>
        <details class="application-resume-prompt"><summary>Read the sample thesis prompt</summary><p class="application-resume-copy">{{ \App\Models\EvaluatorVerification::SAMPLE_PROMPT }}</p></details>
        <h4 class="application-resume-subheading">{{ $adminView ? "The applicant's recommendations" : 'Your recommendations' }}</h4>
        <p class="application-resume-copy">{{ $submission->sample_review }}</p>
        @if($adminView)<p class="hint">Look for a clear concern, a relevant explanation, and practical improvements. Evaluate whether the feedback respects the limits of the sample.</p>@endif
        <p class="application-resume-attestation small"><strong>Accuracy and permission attestation:</strong> {{ $submission->attestation ? ($adminView ? 'Confirmed by the applicant' : 'Confirmed by you') : 'Not confirmed' }}</p>
    </section>
</article>
