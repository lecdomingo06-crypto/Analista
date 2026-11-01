@php($submittedProfile = $submission->profile_snapshot ?? [])
<div class="stack verification-snapshot">
    <div><h3>Submitted professional details</h3><p class="hint">Saved with this application. These details stay available while the admin reviews your submission.</p></div>
    <dl class="verification-profile-summary">
        <div><dt>Display name</dt><dd>{{ $submittedProfile['name'] ?? 'Not provided' }}</dd></div>
        <div><dt>Professional headline</dt><dd>{{ $submittedProfile['headline'] ?? 'Not provided' }}</dd></div>
        <div><dt>Years of experience</dt><dd>{{ $submittedProfile['experience_years'] ?? 0 }}</dd></div>
        <div><dt>Areas of expertise</dt><dd>{{ implode(', ', $submittedProfile['expertise'] ?? []) }}</dd></div>
        <div><dt>Skills</dt><dd>{{ implode(', ', $submittedProfile['skills'] ?? []) ?: 'Not provided' }}</dd></div>
        <div><dt>Review mode</dt><dd>{{ implode(', ', array_map(fn ($mode) => $mode === 'on-site' ? 'On-site' : ucfirst($mode), $submittedProfile['review_modes'] ?? [])) }}</dd></div>
        @if($submittedProfile['portfolio_url'] ?? null)<div class="full"><dt>LinkedIn or portfolio</dt><dd><a class="text-link small" href="{{ $submittedProfile['portfolio_url'] }}" target="_blank" rel="noopener noreferrer">{{ $submittedProfile['portfolio_url'] }} <x-icon name="arrow-up-right" /></a></dd></div>@endif
        <div class="full"><dt>About you</dt><dd class="preserve">{{ $submittedProfile['bio'] ?? 'Not provided' }}</dd></div>
    </dl>
</div>
