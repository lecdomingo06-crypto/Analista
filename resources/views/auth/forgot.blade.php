@extends('layouts.app')
@section('title','Reset your password')
@section('content')
<div class="auth-container"><p class="eyebrow">Let’s get you back in.</p><h1>A fresh start.</h1><p>Enter your account’s email address to request a password reset link.</p><form method="post" action="{{ route('password.email') }}" class="panel fields">@csrf<label class="field">Email address<input type="email" name="email" value="{{ old('email', auth()->user()?->email) }}" required autocomplete="email" autofocus></label><button class="btn btn-primary">Send reset link <x-icon name="arrow-right"/></button></form><p class="auth-switch"><a class="text-link page-back" href="{{ route('login') }}"><x-icon name="arrow-left"/>Back to sign in</a></p></div>
@endsection
