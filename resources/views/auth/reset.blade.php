@extends('layouts.app')
@section('title','Choose a new password')
@section('body-class','registration-page login-page password-recovery-page')
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/register.css') }}?v={{ filemtime(public_path('assets/register.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/password-recovery.css') }}?v={{ filemtime(public_path('assets/password-recovery.css')) }}">
@endpush
@section('content')
@include('auth.partials.register-sky')
<a class="registration-back page-back" href="{{ route('login') }}"><x-icon name="arrow-left"/>Back to sign in</a>
<div class="registration-content">
    <header class="registration-header">
        <div class="registration-mascot" aria-hidden="true">@include('home.partials.community-mascot')</div>
        <h1>Choose a new <em>password.</em></h1>
        <p>A fresh password keeps your account protected. Use at least 10 characters.</p>
    </header>
    <form method="post" action="{{ route('password.update') }}" class="registration-card" aria-label="Choose a new password">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div class="field">
            <label for="reset-email">Email address</label>
            <input id="reset-email" type="email" name="email" value="{{ old('email', $email) }}" maxlength="255" required autocomplete="email" autocapitalize="none" spellcheck="false" placeholder="you@example.com" @error('email') aria-invalid="true" aria-describedby="reset-email-error" @enderror>
            @error('email')<span class="password-recovery-error" id="reset-email-error">{{ $message }}</span>@enderror
        </div>
        <div class="field">
            <label for="reset-password">New password</label>
            <input id="reset-password" type="password" name="password" required minlength="10" autocomplete="new-password" placeholder="At least 10 characters" @error('password') aria-invalid="true" aria-describedby="reset-password-error" @enderror>
            @error('password')<span class="password-recovery-error" id="reset-password-error">{{ $message }}</span>@enderror
        </div>
        <div class="field">
            <label for="reset-password-confirmation">Confirm password</label>
            <input id="reset-password-confirmation" type="password" name="password_confirmation" required minlength="10" autocomplete="new-password" placeholder="Enter your new password again" @error('password_confirmation') aria-invalid="true" aria-describedby="reset-password-confirmation-error" @enderror>
            @error('password_confirmation')<span class="password-recovery-error" id="reset-password-confirmation-error">{{ $message }}</span>@enderror
        </div>
        <button class="btn registration-submit" type="submit">Reset password <x-icon name="arrow-right"/></button>
    </form>
    <p class="registration-signin">Need another link? <a href="{{ route('password.request') }}">Request a reset link <x-icon name="arrow-up-right"/></a></p>
</div>
@endsection
