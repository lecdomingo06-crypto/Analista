@extends('layouts.app')
@section('title','Choose a new password')
@section('content')
<div class="auth-container"><p class="eyebrow">Your next chapter awaits.</p><h1>Choose a new password.</h1><p>Use at least 10 characters to keep your account protected.</p><form method="post" action="{{ route('password.update') }}" class="panel fields">@csrf<input type="hidden" name="token" value="{{ $token }}"><label class="field">Email address<input type="email" name="email" value="{{ old('email',$email) }}" required autocomplete="email"></label><label class="field">New password<input type="password" name="password" required minlength="10" autocomplete="new-password"></label><label class="field">Confirm password<input type="password" name="password_confirmation" required autocomplete="new-password"></label><button class="btn btn-primary">Reset password <x-icon name="arrow-right"/></button></form></div>
@endsection
