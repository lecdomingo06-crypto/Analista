@extends('layouts.app')
@section('title', 'Complete your account')
@section('crumb', 'create an account')
@section('body-class', 'registration-page')
@push('styles')
<link rel="preload" href="{{ asset('assets/fonts/Newsreader-Italic-Latin.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/register.css') }}?v={{ filemtime(public_path('assets/register.css')) }}">
@endpush
@section('content')
@include('auth.partials.register-sky')
<a class="registration-back page-back" href="{{ route('login') }}"><x-icon name="arrow-left"/>Back to sign in</a>
<div class="registration-content">
    <header class="registration-header">
        <div class="registration-mascot">@include('home.partials.community-mascot')</div>
        <h1>Make yourself <em>at home.</em></h1>
        <p>Your Google account is verified. Choose how you would like to join Analista.</p>
    </header>
    @if($invitation)<div class="registration-invitation" role="status">You have been invited to apply as a volunteer evaluator.</div>@endif
    <form method="post" action="{{ route('auth.google.store') }}" class="registration-card">
        @csrf
        <div class="registration-google-identity">
            <img src="{{ asset('assets/google-g.png') }}" width="20" height="20" alt="">
            <div><span>Verified Google account</span><strong>{{ $email }}</strong></div>
        </div>
        <label class="field">Full name<input name="name" value="{{ old('name', $name) }}" required maxlength="120" autocomplete="name" autofocus></label>
        <fieldset class="registration-roles">
            <legend>I am joining as a...</legend>
            <div class="registration-role-options">
                @unless($invitation)
                <label class="registration-role">
                    <input type="radio" name="role" value="student" @checked(old('role', $role) === 'student') required>
                    <span class="registration-role-copy"><strong>Student</strong><span>I am working on a thesis.</span></span>
                </label>
                @endunless
                <label class="registration-role">
                    <input type="radio" name="role" value="evaluator" @checked($invitation || old('role', $role) === 'evaluator') required>
                    <span class="registration-role-copy"><strong>Evaluator</strong><span>I would like to volunteer.</span></span>
                </label>
            </div>
        </fieldset>
        <label class="registration-terms"><input type="checkbox" name="terms" value="1" required @checked(old('terms'))><span>I agree to the <a href="{{ route('guidelines') }}" target="_blank" rel="noopener">community guidelines and privacy terms</a>.</span></label>
        <button class="btn registration-submit" type="submit">Create your account <x-icon name="arrow-right"/></button>
        <p class="registration-note">You will use Google to sign in. Evaluator applications are reviewed before profiles appear in the community.</p>
    </form>
</div>
@endsection
