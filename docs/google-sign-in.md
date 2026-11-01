# Google sign-in

Analista supports email/password and Google sign-in through Laravel Socialite.

## Local setup

1. In Google Auth Platform, create a **Web application** OAuth client.
2. Register the exact callback URL used by your local server, for example `http://localhost:8000/auth/google/callback`.
3. Add your Google account under **Audience → Test users** while the app is in testing.
4. Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and `GOOGLE_REDIRECT_URI` in your local `.env`. Never commit these credentials. The button appears when all three settings are present.
5. If configuration is cached, run `php artisan config:clear`. Open Analista on the same host and port as the callback and select **Continue with Google**.

Use a real Google account for this check; the seeded `@analista.test` accounts cannot authenticate with Google. When deploying, add the production HTTPS callback in Google and update the environment settings.

## Account behavior

- A new Google user confirms their name, chooses Student or Evaluator, and accepts the community guidelines and privacy terms. New evaluators remain pending and complete professional verification.
- If the verified Google email already belongs to an Analista account, the user must enter that account's existing password once before Google is linked. An email match alone never links accounts.
- Subsequent Google sign-ins use the linked Google account ID. Existing roles, profiles, passwords, and evaluator approvals are preserved. Suspended accounts remain blocked.
- Google-only accounts have no local password. **My profile → Add a password** opens the existing email password-reset flow. Email delivery must be configured to receive the link; the local `log` mailer writes mail to the application log.
- Analista stores the linked Google account ID privately. It does not save Google access tokens, refresh tokens, or Google profile photos. Pending signup and linking sessions expire after ten minutes.

## Troubleshooting

- **Redirect URI mismatch:** match scheme, host, port, and `/auth/google/callback` exactly. `localhost` and `127.0.0.1` are different hosts.
- **Access denied during testing:** add the account to Google's test-user list.
- **Expired sign-in:** restart from **Continue with Google** in the same browser.
- **Existing account password forgotten:** use **Forgot password?**, reset the password, and then restart Google sign-in to link it.

References: [Laravel Socialite](https://laravel.com/docs/12.x/socialite), [Google OpenID Connect](https://developers.google.com/identity/openid-connect/openid-connect), [Google consent configuration](https://developers.google.com/workspace/guides/configure-oauth-consent), [Google button branding](https://developers.google.com/identity/branding-guidelines).
