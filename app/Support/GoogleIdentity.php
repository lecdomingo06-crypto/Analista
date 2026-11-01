<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GoogleIdentity
{
    public const PENDING_REGISTRATION = 'google_auth.registration';

    public const PENDING_LINK = 'google_auth.link';

    public const CONTEXT = 'google_auth.context';

    public const LIFETIME = 600;

    public static function pending(Request $request, string $key): ?array
    {
        $identity = $request->session()->get($key);
        if (! is_array($identity)
            || ! is_string($identity['id'] ?? null)
            || trim($identity['id']) === ''
            || strlen($identity['id']) > 255
            || ! is_string($identity['email'] ?? null)
            || strlen($identity['email']) > 255
            || ! filter_var($identity['email'], FILTER_VALIDATE_EMAIL)
            || ! is_string($identity['name'] ?? null)
            || ! is_int($identity['expires_at'] ?? null)
            || $identity['expires_at'] <= now()->timestamp) {
            $request->session()->forget($key);

            return null;
        }

        return $identity;
    }

    public static function forget(Request $request): void
    {
        $request->session()->forget([
            self::PENDING_REGISTRATION, self::PENDING_LINK, self::CONTEXT, 'state',
        ]);
    }

    /**
     * A matching email alone is never enough to link an existing account.
     * This hook runs only after the account's local password has been checked.
     */
    public static function linkAfterPasswordLogin(Request $request, User $user): void
    {
        $identity = self::pending($request, self::PENDING_LINK);
        self::forget($request);
        if (! $identity || Str::lower($user->email) !== $identity['email']) {
            return;
        }

        try {
            $linked = DB::transaction(function () use ($identity, $user): bool {
                $account = User::query()->lockForUpdate()->find($user->id);
                if (! $account || $account->is_suspended
                    || Str::lower($account->email) !== $identity['email']
                    || ($account->google_id !== null && $account->google_id !== $identity['id'])
                    || User::query()->where('google_id', $identity['id'])->whereKeyNot($account->id)->exists()) {
                    return false;
                }

                $account->google_id = $identity['id'];
                $account->save();

                return true;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // Another request claimed this identity. The password login is still valid.
            $linked = false;
        }

        if ($linked) {
            $user->google_id = $identity['id'];
            $request->session()->flash('success', 'Google is now linked to your account. You can use either sign-in option.');
        }
    }
}
