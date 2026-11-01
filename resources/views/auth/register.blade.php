@extends('layouts.app')
@section('title','Join the community')
@section('crumb','create an account')
@section('body-class','registration-page')
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/register.css') }}?v={{ filemtime(public_path('assets/register.css')) }}">
@endpush
@section('content')
@include('auth.partials.register-sky')
<a class="registration-back page-back" href="{{ route('home') }}"><x-icon name="arrow-left"/>Back to home</a>
<div class="registration-content">
    <header class="registration-header">
        <div class="registration-mascot">@include('home.partials.community-mascot')</div>
        <h1>Find your <em>people.</em></h1>
        <p>Bring your questions or share your expertise. Analista is free and open to everyone.</p>
    </header>
    @if($invitation)<div class="registration-invitation" role="status">You’ve been invited to apply as a volunteer evaluator.</div>@endif
    <form method="post" action="{{ route('register') }}" class="registration-card">
        @csrf
        <input type="hidden" name="invite" value="{{ request('invite') }}">
        <fieldset class="registration-roles">
            <legend>I’m joining as a…</legend>
            <div class="registration-role-options">
                <label class="registration-role">
                    <input type="radio" name="role" value="student" @checked(old('role',request('role',$invitation ? 'evaluator' : 'student'))==='student') required>
                    <span class="registration-role-copy"><strong>Student</strong><span>I’m working on a thesis.</span></span>
                </label>
                <label class="registration-role">
                    <input type="radio" name="role" value="evaluator" @checked(old('role',request('role',$invitation ? 'evaluator' : 'student'))==='evaluator')>
                    <span class="registration-role-copy"><strong>Evaluator</strong><span>I’d like to volunteer.</span></span>
                </label>
            </div>
        </fieldset>
        <label class="field">Full name<input name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name" placeholder="How should we call you?"></label>
        <label class="field">Email address<input type="email" name="email" value="{{ old('email',$invitation?->email) }}" required autocomplete="email" placeholder="you@example.com"></label>
        <div class="registration-passwords">
            <label class="field">Password<input type="password" name="password" required minlength="10" autocomplete="new-password" placeholder="At least 10 characters"></label>
            <label class="field">Confirm password<input type="password" name="password_confirmation" required autocomplete="new-password" placeholder="Enter your password again"></label>
        </div>
        <label class="registration-terms"><input type="checkbox" name="terms" value="1" required @checked(old('terms'))><span>I agree to the <a href="{{ route('guidelines') }}" target="_blank" rel="noopener">community guidelines and privacy terms</a>.</span></label>
        <button class="btn registration-submit" type="submit">Create your account <x-icon name="arrow-right"/></button>
        <p class="registration-note">Evaluator applications are reviewed before profiles appear in the community.</p>
        @include('auth.partials.google-button', ['googleRegistration' => true])
    </form>
    <p class="registration-signin">Already part of the community? <a href="{{ route('login') }}">Sign in <x-icon name="arrow-up-right"/></a></p>
</div>
@endsection
