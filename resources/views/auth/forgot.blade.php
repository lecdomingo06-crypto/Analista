@extends('layouts.app')
@section('title','Reset your password')
@section('body-class','registration-page login-page password-recovery-page')
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/register.css') }}?v={{ filemtime(public_path('assets/register.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/password-recovery.css') }}?v={{ filemtime(public_path('assets/password-recovery.css')) }}">
@endpush
@section('content')
@include('auth.partials.register-sky')
<a class="registration-back page-back" href="{{ auth()->check() ? route('account.profile') : route('login') }}"><x-icon name="arrow-left"/>{{ auth()->check() ? 'Back to my profile' : 'Back to sign in' }}</a>
<div class="registration-content">
    <header class="registration-header">
        <div class="registration-mascot" aria-hidden="true">@include('home.partials.community-mascot')</div>
        <h1>A fresh <em>start.</em></h1>
        <p>Enter your account’s email address and we’ll send you a password reset link.</p>
    </header>
    <form method="post" action="{{ route('password.email') }}" class="registration-card" aria-label="Request a password reset link">
        @csrf
        <div class="field">
            <label for="recovery-email">Email address</label>
            <input id="recovery-email" type="email" name="email" value="{{ old('email', auth()->user()?->email) }}" maxlength="255" required autocomplete="email" autofocus autocapitalize="none" spellcheck="false" placeholder="you@example.com" @error('email') aria-invalid="true" aria-describedby="recovery-email-error" @enderror>
            @error('email')<span class="password-recovery-error" id="recovery-email-error">{{ $message }}</span>@enderror
        </div>
        <button class="btn registration-submit" type="submit">Send reset link <x-icon name="arrow-right"/></button>
    </form>
    <p class="registration-signin">Need a hand? <a href="{{ route('faq') }}">Visit our FAQ <x-icon name="arrow-up-right"/></a></p>
</div>
@endsection
