@php
    $profileExpertise = old('profile.expertise', $user->expertise ?? []);
    $profileExpertise = is_array($profileExpertise) ? $profileExpertise : [];
    $profileModes = old('profile.review_modes', $user->review_modes ?? []);
    $profileModes = is_array($profileModes) ? $profileModes : [];
    $profileSkills = old('profile.skills', implode(', ', $user->skills ?? []));
    $profileSkills = is_array($profileSkills) ? implode(', ', array_filter($profileSkills, 'is_string')) : $profileSkills;
@endphp
<div class="stack">
    <div><h3>Your professional details</h3><p class="small muted">Manage your professional background here. These details and your proof are saved together when you submit this application.</p></div>
    <div class="form-grid">
        <div class="field"><label for="verification-profile-name">Display name</label><input id="verification-profile-name" name="profile[name]" value="{{ old('profile.name', $user->name) }}" maxlength="100" required autocomplete="name"></div>
        <div class="field"><label for="verification-profile-city">City <span class="hint">Optional</span></label><input id="verification-profile-city" name="profile[city]" value="{{ old('profile.city', $user->city) }}" maxlength="120" autocomplete="address-level2"></div>
        <div class="field full"><label for="verification-profile-headline">Professional headline</label><input id="verification-profile-headline" name="profile[headline]" value="{{ old('profile.headline', $user->headline) }}" maxlength="180" placeholder="e.g. Software Engineer · Web & Mobile Developer" required></div>
        <div class="field"><label for="verification-profile-experience">Years of experience</label><input id="verification-profile-experience" name="profile[experience_years]" type="number" min="0" max="60" value="{{ old('profile.experience_years', $user->experience_years ?? 0) }}" required></div>
        <div class="field"><label for="verification-profile-capacity">Maximum simultaneous reviews</label><input id="verification-profile-capacity" name="profile[capacity]" type="number" min="1" max="10" value="{{ old('profile.capacity', $user->capacity ?? 3) }}" required></div>
    </div>
    <fieldset class="field"><legend>Areas of expertise <span class="hint">Choose 1–6</span></legend><div class="actions">
        @foreach($expertiseOptions as $expertise)<label class="badge"><input type="checkbox" name="profile[expertise][]" value="{{ $expertise }}" @checked(in_array($expertise, $profileExpertise, true))> {{ $expertise }}</label>@endforeach
    </div></fieldset>
    <div class="field"><label for="verification-profile-skills">Skills <span class="hint">Optional</span></label><input id="verification-profile-skills" name="profile[skills]" value="{{ $profileSkills }}" maxlength="1000" placeholder="Laravel, React, MySQL"><span class="hint">Separate skills with commas.</span></div>
    <fieldset class="field"><legend>How can you review?</legend><div class="actions">
        <label class="small"><input type="checkbox" name="profile[review_modes][]" value="online" @checked(in_array('online', $profileModes, true))> Online</label>
        <label class="small"><input type="checkbox" name="profile[review_modes][]" value="on-site" @checked(in_array('on-site', $profileModes, true))> On-site</label>
    </div><span class="hint">Choose at least one review mode.</span></fieldset>
    <div class="field"><label for="verification-profile-portfolio">LinkedIn or portfolio <span class="hint">Optional</span></label><input id="verification-profile-portfolio" name="profile[portfolio_url]" type="url" value="{{ old('profile.portfolio_url', $user->portfolio_url) }}" maxlength="500" placeholder="https://your-portfolio.example"></div>
    <div class="field"><label for="verification-profile-bio">About you</label><textarea id="verification-profile-bio" name="profile[bio]" rows="4" minlength="30" maxlength="5000" required placeholder="Describe your background and what you can help students improve.">{{ old('profile.bio', $user->bio) }}</textarea><span class="hint">At least 30 characters. These professional details will appear on your public profile after approval.</span></div>
</div>
