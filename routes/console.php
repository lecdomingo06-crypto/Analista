<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('analista:admin {email} {--name=Administrator}', function () {
    $email = strtolower($this->argument('email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $this->error('Enter a valid email address.'); return 1; }
    if (\App\Models\User::where('email', $email)->exists()) { $this->error('An account already exists for this email.'); return 1; }
    $password = $this->secret('Choose a password (at least 12 characters)');
    if (strlen((string)$password) < 12) { $this->error('Use at least 12 characters.'); return 1; }
    if ($password !== $this->secret('Confirm password')) { $this->error('Passwords did not match.'); return 1; }
    \App\Models\User::create(['name' => $this->option('name'), 'email' => $email, 'password' => $password, 'role' => 'admin', 'email_verified_at' => now()]);
    $this->info('Administrator created. You can sign in now.');
    return 0;
})->purpose('Create an Analista administrator with an interactive password');
