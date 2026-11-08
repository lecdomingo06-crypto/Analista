@extends('layouts.app')
@section('title','Welcome back')
@section('crumb','sign in')
@section('body-class','registration-page login-page')
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
        <h1>Welcome <em>back.</em></h1>
        <p>A fresh draft. A new recommendation. Let’s keep going.</p>
    </header>
    <form method="post" action="{{ route('login') }}" class="registration-card">
        @csrf
        <label class="field">Email address or admin username<input type="text" name="email" value="{{ old('email') }}" maxlength="255" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="Email address" aria-describedby="login-identifier-help"><span class="hint" id="login-identifier-help">Students and evaluators must use their email address.</span></label>
        <label class="field">Password<input type="password" name="password" required autocomplete="current-password" placeholder="Enter your password"></label>
        <div class="registration-login-options">
            <label class="registration-terms"><input type="checkbox" name="remember" value="1">Remember me</label>
            <a href="{{ route('password.request') }}">Forgot password?</a>
        </div>
        <button class="btn registration-submit" type="submit">Sign in <x-icon name="arrow-right"/></button>
        @include('auth.partials.google-button')
    </form>
    <p class="registration-signin">New here? <a href="{{ route('register') }}">Join the community <x-icon name="arrow-up-right"/></a></p>
</div>
@endsection
