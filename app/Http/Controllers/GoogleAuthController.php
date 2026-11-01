<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\User;
use App\Support\GoogleIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        GoogleIdentity::forget($request);
        if (! $this->configured()) {
            return $this->failed($request, 'Google sign-in is not available yet. Please use your email and password.');
        }

        $invite = $request->query('invite');
        if ($invite !== null && (! is_string($invite) || strlen($invite) > 128 || ! $this->invitation($invite))) {
            return $this->failed($request, 'This invitation has expired or is no longer available.');
        }

        $request->session()->put(GoogleIdentity::CONTEXT, [
            'invite' => $invite,
            'role' => $invite || $request->query('role') === 'evaluator' ? 'evaluator' : 'student',
            'expires_at' => now()->timestamp + GoogleIdentity::LIFETIME,
        ]);

        try {
            return Socialite::driver('google')->scopes(['openid', 'profile', 'email'])->redirect();
        } catch (Throwable) {
            return $this->failed($request);
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! $this->configured() || $request->has('error') || ! is_string($request->query('code')) || $request->query('code') === '') {
            return $this->failed($request);
        }

        try {
            // Socialite validates and consumes the browser session's OAuth state.
            $google = Socialite::driver('google')->user();
            $raw = $google->getRaw();
            $verified = $raw['email_verified'] ?? $raw['verified_email'] ?? false;
            $id = $google->getId();
            $email = $google->getEmail();
            if ($verified !== true || ! is_string($id) || trim($id) === '' || strlen($id) > 255
                || ! is_string($email) || strlen($email) > 255 || ! filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
                return $this->failed($request, 'Google could not verify this email address. Please use a verified Google account.');
            }
            $identity = [
                'id' => $id,
                'email' => Str::lower(trim($email)),
                'name' => Str::limit(trim((string) $google->getName()), 120, ''),
                'expires_at' => now()->timestamp + GoogleIdentity::LIFETIME,
            ];
        } catch (Throwable) {
            // Provider responses may contain credentials; never store or log them.
            return $this->failed($request);
        }

        $context = $request->session()->get(GoogleIdentity::CONTEXT);
        if (! is_array($context) || ! is_int($context['expires_at'] ?? null) || $context['expires_at'] <= now()->timestamp) {
            return $this->failed($request, 'Your Google sign-in has expired. Please start again.');
        }
        if (($context['invite'] ?? null) && ! $this->matchingInvitation($context['invite'], $identity['email'])) {
            return $this->failed($request, 'This invitation has expired or belongs to a different email address.');
        }

        $user = User::query()->where('google_id', $identity['id'])->first();
        if ($user) {
            GoogleIdentity::forget($request);

            return $this->login($request, $user);
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$identity['email']])->first();
        $request->session()->forget([GoogleIdentity::PENDING_REGISTRATION, GoogleIdentity::PENDING_LINK]);
        if ($user) {
            if ($user->is_suspended) {
                return $this->failed($request, 'Your account is suspended. Please contact the administrator.');
            }
            if ($user->google_id !== null) {
                return $this->failed($request, 'This account is linked to a different Google account. Please use its existing sign-in method.');
            }

            $request->session()->put(GoogleIdentity::PENDING_LINK, $identity);

            return redirect()->route('login')->withInput(['email' => $identity['email']])->with('success',
                'An Analista account already uses this email. Sign in with its existing password to securely link Google.');
        }

        $request->session()->put(GoogleIdentity::PENDING_REGISTRATION, $identity);

        return redirect()->route('auth.google.complete');
    }

    public function completeForm(Request $request): View|RedirectResponse
    {
        $identity = GoogleIdentity::pending($request, GoogleIdentity::PENDING_REGISTRATION);
        if (! $identity) {
            return $this->failed($request, 'Your Google sign-in has expired. Please start again.');
        }

        $context = $request->session()->get(GoogleIdentity::CONTEXT, []);
        $invitation = null;
        if ($context['invite'] ?? null) {
            $invitation = $this->matchingInvitation($context['invite'], $identity['email']);
            if (! $invitation || ($context['expires_at'] ?? 0) <= now()->timestamp) {
                return $this->failed($request, 'This invitation has expired or belongs to a different email address.');
            }
        }

        return view('auth.google-complete', [
            'name' => $identity['name'],
            'email' => $identity['email'],
            'role' => $invitation || ($context['role'] ?? '') === 'evaluator' ? 'evaluator' : 'student',
            'invitation' => $invitation,
        ]);
    }

    public function completeRegistration(Request $request): RedirectResponse
    {
        $identity = GoogleIdentity::pending($request, GoogleIdentity::PENDING_REGISTRATION);
        if (! $identity) {
            return $this->failed($request, 'Your Google sign-in has expired. Please start again.');
        }
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'role' => ['required', 'in:student,evaluator'],
            'terms' => ['accepted'],
        ]);
        $context = $request->session()->get(GoogleIdentity::CONTEXT, []);

        try {
            $user = DB::transaction(function () use ($identity, $context, $data): User {
                $invitation = null;
                if ($context['invite'] ?? null) {
                    $invitation = $this->matchingInvitation($context['invite'], $identity['email'], true);
                    if (! $invitation || ($context['expires_at'] ?? 0) <= now()->timestamp) {
                        throw ValidationException::withMessages(['email' => 'This invitation has expired or belongs to a different email address.']);
                    }
                }
                if (User::query()->where('google_id', $identity['id'])->orWhereRaw('LOWER(email) = ?', [$identity['email']])->exists()) {
                    throw ValidationException::withMessages(['email' => 'An account already uses this identity. Please start Google sign-in again.']);
                }

                $isEvaluator = $invitation !== null || $data['role'] === 'evaluator';
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $identity['email'],
                    'google_id' => $identity['id'],
                    'password' => null,
                    'email_verified_at' => now(),
                    'role' => $isEvaluator ? 'evaluator' : 'student',
                    'evaluator_status' => $isEvaluator ? 'pending' : 'none',
                    'accepting_requests' => false,
                ]);
                if ($invitation) {
                    $invitation->update(['accepted_at' => now()]);
                }
                if ($isEvaluator) {
                    User::where('role', 'admin')->where('is_suspended', false)->each(fn (User $admin) => $admin->notifyActivity(
                        'New evaluator application', $user->name.' has joined. Review their completed profile.',
                        route('admin.index', ['section' => 'evaluators'])
                    ));
                }

                return $user;
            }, 3);
        } catch (ValidationException $exception) {
            return $this->failed($request, $exception->validator->errors()->first('email'));
        } catch (UniqueConstraintViolationException) {
            return $this->failed($request, 'An account already uses this identity. Please start Google sign-in again.');
        }

        GoogleIdentity::forget($request);
        Auth::login($user);
        $request->session()->regenerate();
        if ($user->role === 'evaluator') {
            return redirect()->route('account.evaluator-verification.show')->with('success',
                'Welcome! Upload your professional proof and complete your details here to apply as an evaluator.');
        }

        return redirect()->route('account.dashboard')->with('success', 'Welcome to Analista. Your next chapter starts here.');
    }

    private function login(Request $request, User $user): RedirectResponse
    {
        if ($user->is_suspended) {
            return $this->failed($request, 'Your account is suspended. Please contact the administrator.');
        }
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route($user->isAdmin() ? 'admin.index' : 'account.dashboard'));
    }

    private function configured(): bool
    {
        foreach (['client_id', 'client_secret', 'redirect'] as $key) {
            $value = config('services.google.'.$key);
            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    private function invitation(string $token, bool $lock = false): ?Invitation
    {
        return Invitation::query()->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')->where('expires_at', '>', now())
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    private function matchingInvitation(string $token, string $email, bool $lock = false): ?Invitation
    {
        $invitation = $this->invitation($token, $lock);

        return $invitation && Str::lower($invitation->email) === $email ? $invitation : null;
    }

    private function failed(Request $request, string $message = 'Google sign-in could not be completed. Please try again or use your email and password.'): RedirectResponse
    {
        GoogleIdentity::forget($request);

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
