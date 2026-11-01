@extends('layouts.app')
@section('title', 'Your profile')
@section('body-class', 'evaluators-page account-profile-page')
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/evaluators.css') }}?v={{ filemtime(public_path('assets/evaluators.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/profile.css') }}?v={{ filemtime(public_path('assets/profile.css')) }}">
@endpush
@push('scripts')
<script src="{{ asset('assets/profile.js') }}?v={{ filemtime(public_path('assets/profile.js')) }}" defer></script>
@endpush
@section('content')
@include('evaluators.partials.sky')
<a class="evaluators-back page-back profile-back" href="{{ route('home') }}"><x-icon name="arrow-left"/>Back to home</a>
<header class="profile-page-header">
    <h1>Your <em>profile.</em></h1>
    <p>Update your profile picture, display name, and password.</p>
</header>
@if($user->role === 'evaluator')
    <div class="panel stack profile-verification">
        <div class="actions"><strong class="small">Evaluator verification</strong><x-status :status="$user->evaluator_status" /></div>
        <p class="small muted">Manage your professional details, expertise, and supporting proof on the Verification page.</p>
        @if($user->moderation_note)<p class="small"><strong>Admin note:</strong> {{ $user->moderation_note }}</p>@endif
        <a class="text-link small" href="{{ route('account.evaluator-verification.show') }}">Manage verification <x-icon name="arrow-right" /></a>
    </div>
@endif

<div class="profile-settings-grid">
<form method="POST" action="{{ route('account.profile.update') }}" enctype="multipart/form-data" class="panel stack profile-details-form" aria-labelledby="profile-details-title">
    @csrf @method('PUT')
    <div><h2 class="profile-panel-title" id="profile-details-title">Personal details.</h2></div>
    <section class="profile-photo-section" aria-labelledby="profile-photo-label">
        <x-avatar :user="$user" class="profile-photo-avatar" data-profile-photo-preview :data-initials="$user->initials()" :data-original-photo="$user->profile_photo_url" />
        <div class="field profile-photo-controls">
            <label for="profile_photo" id="profile-photo-label">Profile picture</label>
            <input id="profile_photo" name="profile_photo" type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="profile-photo-help profile-photo-feedback" @error('profile_photo') aria-invalid="true" @enderror>
            <span class="hint" id="profile-photo-help">JPG, PNG or WebP. Up to 2 MB and 4096 × 4096 pixels. Your photo is visible to the community.</span>
            <span class="hint" id="profile-photo-feedback" role="status" aria-live="polite">@error('profile_photo'){{ $message }}@enderror</span>
        </div>
    </section>
    <div class="form-grid">
        <div class="field full"><label for="name">Display name</label><input id="name" name="name" value="{{ old('name', $user->name) }}" maxlength="100" required autocomplete="name" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>@error('name')<span class="hint field-error" id="name-error">{{ $message }}</span>@enderror @if($user->isEvaluator())<span class="hint">Changing your display name requires a new verification review.</span>@endif</div>
        <div class="field"><label for="email">Email</label><input id="email" type="email" value="{{ $user->email }}" readonly><span class="hint">Your account email is kept private.</span></div>
        <div class="field"><label for="city">City <span class="hint">Optional</span></label><input id="city" name="city" value="{{ old('city', $user->city) }}" maxlength="120" placeholder="e.g. Quezon City" autocomplete="address-level2"></div>
    </div>

    @if($user->isEvaluator())
    <section class="stack profile-availability" id="review-availability">
        <div><h3 class="profile-availability-title">Review availability</h3><p class="small muted">Choose when you can accept requests and a workload you can comfortably manage.</p></div>
        <label class="small"><input type="checkbox" name="accepting_requests" value="1" @checked(old('accepting_requests', $user->accepting_requests))> Accepting review requests</label>
        <div class="field"><label for="capacity">Maximum simultaneous reviews</label><input id="capacity" name="capacity" type="number" min="1" max="10" value="{{ old('capacity', $user->capacity ?? 3) }}" required></div>
    </section>
    @endif
    <div class="actions"><button class="btn btn-primary" type="submit">Save profile <x-icon name="arrow-up-right" /></button><a class="text-link small page-back" href="{{ route('account.dashboard') }}">Back to your space</a></div>
</form>

@if($user->getAuthPassword())
<form method="POST" action="{{ route('account.profile.password') }}" class="panel stack profile-password-form" id="password" aria-labelledby="profile-password-title">
    @csrf @method('PUT')
    <div><h2 class="profile-panel-title" id="profile-password-title">Change your password.</h2><p class="small muted">Confirm your current password, then choose a new one.</p></div>
    <input type="text" name="username" value="{{ $user->email }}" autocomplete="username" hidden>
    <div class="field">
        <label for="current_password">Current password</label>
        <div class="profile-password-input"><input id="current_password" name="current_password" type="password" required autocomplete="current-password" @error('current_password') aria-invalid="true" aria-describedby="current-password-error" @enderror><button type="button" class="profile-password-toggle" data-password-toggle="current_password" aria-label="Show current password" aria-pressed="false" hidden>Show</button></div>
        @error('current_password')<span class="hint field-error" id="current-password-error">{{ $message }}</span>@enderror
    </div>
    <div class="field">
        <label for="new_password">New password</label>
        <div class="profile-password-input"><input id="new_password" name="password" type="password" required minlength="10" maxlength="72" autocomplete="new-password" aria-describedby="new-password-help @error('password') new-password-error @enderror" @error('password') aria-invalid="true" @enderror><button type="button" class="profile-password-toggle" data-password-toggle="new_password" aria-label="Show new password" aria-pressed="false" hidden>Show</button></div>
        <span class="hint" id="new-password-help">At least 10 characters. Choose something you don’t use elsewhere.</span>
        @error('password')<span class="hint field-error" id="new-password-error">{{ $message }}</span>@enderror
    </div>
    <div class="field">
        <label for="password_confirmation">Confirm new password</label>
        <div class="profile-password-input"><input id="password_confirmation" name="password_confirmation" type="password" required minlength="10" maxlength="72" autocomplete="new-password" @error('password_confirmation') aria-invalid="true" aria-describedby="password-confirmation-error" @enderror><button type="button" class="profile-password-toggle" data-password-toggle="password_confirmation" aria-label="Show password confirmation" aria-pressed="false" hidden>Show</button></div>
        @error('password_confirmation')<span class="hint field-error" id="password-confirmation-error">{{ $message }}</span>@enderror
    </div>
    <div class="actions"><button class="btn btn-primary" type="submit">Update password <x-icon name="lock" /></button></div>
</form>
@else
<section class="panel stack profile-password-form" id="password" aria-labelledby="profile-password-title">
    <div><h2 class="profile-panel-title" id="profile-password-title">Add a password.</h2><p class="small muted">You sign in with Google. Request an email link to set a password for email sign-in.</p></div>
    <div class="actions"><a class="btn btn-primary" href="{{ route('password.request') }}">Request a password link <x-icon name="arrow-right" /></a></div>
</section>
@endif
</div>
@endsection
