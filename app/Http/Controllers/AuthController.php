<?php

namespace App\Http\Controllers;

use App\Models\{Invitation, User};
use App\Support\GoogleIdentity;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Hash, Password, RateLimiter};
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function sessionStatus(Request $request)
    {
        return response()->json([
            'authenticated' => $request->user() !== null,
            'user_id' => $request->user()?->id,
        ])->withHeaders([
            'Cache-Control' => 'private, no-store, no-cache, max-age=0, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    public function loginForm() { return view('auth.login'); }
    public function registerForm(Request $request) { return view('auth.register', ['invitation' => $this->invitation($request->query('invite'))]); }
    public function login(Request $request)
    {
        $this->normalizeEmail($request);
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $key = 'login:'.Str::lower($credentials['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) throw ValidationException::withMessages(['email' => 'Please try again in '.RateLimiter::availableIn($key).' seconds.']);
        if (!Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }
        if (Auth::user()->is_suspended) {
            GoogleIdentity::forget($request);
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'Your account is suspended. Please contact the administrator.']);
        }
        RateLimiter::clear($key);
        GoogleIdentity::linkAfterPasswordLogin($request, Auth::user());
        $request->session()->regenerate();
        return redirect()->intended(route(Auth::user()->isAdmin() ? 'admin.index' : 'account.dashboard'));
    }
    public function register(Request $request)
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            'name' => 'required|string|max:120', 'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', PasswordRule::min(10)], 'role' => 'required|in:student,evaluator',
            'terms' => 'accepted', 'invite' => 'nullable|string|max:128',
        ]);
        $invitation = $this->invitation($request->input('invite'));
        if ($request->filled('invite') && (!$invitation || Str::lower($data['email']) !== Str::lower($invitation->email))) throw ValidationException::withMessages(['email' => 'This invitation is expired or belongs to a different email address.']);
        $user = DB::transaction(function () use ($data, $invitation) {
            $isEvaluator = $invitation || $data['role'] === 'evaluator';
            $user = User::create(['name' => $data['name'], 'email' => Str::lower($data['email']), 'password' => $data['password'], 'role' => $isEvaluator ? 'evaluator' : 'student', 'evaluator_status' => $isEvaluator ? 'pending' : 'none', 'accepting_requests' => false]);
            if ($invitation) $invitation->update(['accepted_at' => now()]);
            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();
        if ($user->role === 'evaluator') {
            User::where('role', 'admin')->each(fn ($admin) => $admin->notifyActivity('New evaluator application', $user->name.' has joined. Review their completed profile.', route('admin.index', ['section' => 'evaluators'])));
            return redirect()->route('account.evaluator-verification.show')->with('success', 'Welcome! Upload your professional proof and complete your details here to apply as an evaluator.');
        }
        return redirect()->route('account.dashboard')->with('success', 'Welcome to Analista. Your next chapter starts here.');
    }
    public function logout(Request $request)
    {
        Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        return redirect()->route('home');
    }
    public function forgot() { return view('auth.forgot'); }
    public function emailReset(Request $request)
    {
        $this->normalizeEmail($request);
        $request->validate(['email' => 'required|email']);
        Password::sendResetLink($request->only('email'));
        return back()->with('success', 'If this email has an account, a password reset link has been sent.');
    }
    public function resetForm(Request $request, string $token) { return view('auth.reset', ['token' => $token, 'email' => $request->query('email')]); }
    public function reset(Request $request)
    {
        $this->normalizeEmail($request);
        $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(10)]]);
        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });
        return $status === Password::PASSWORD_RESET ? redirect()->route('login')->with('success', __($status)) : back()->withErrors(['email' => __($status)]);
    }
    private function normalizeEmail(Request $request): void
    {
        if (is_string($request->input('email'))) $request->merge(['email' => Str::lower(trim($request->input('email')))]);
    }
    private function invitation(?string $token): ?Invitation
    {
        return $token ? Invitation::where('token_hash', hash('sha256', $token))->whereNull('accepted_at')->where('expires_at', '>', now())->first() : null;
    }
}
