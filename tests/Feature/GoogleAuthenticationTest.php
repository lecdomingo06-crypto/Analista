<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\User;
use App\Support\GoogleIdentity;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private string $ownedViews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->ownedViews = storage_path('framework/testing/google-auth-'.bin2hex(random_bytes(8)));
        (new Filesystem)->ensureDirectoryExists($this->ownedViews);
        config([
            'app.debug' => false,
            'view.compiled' => $this->ownedViews,
            'services.google.client_id' => 'fake-test-client.apps.googleusercontent.com',
            'services.google.client_secret' => 'fake-test-secret',
            'services.google.redirect' => 'http://localhost/auth/google/callback',
        ]);
        $this->app->forgetInstance('blade.compiler');
        $this->app['view.engine.resolver']->forget('blade');
    }

    protected function tearDown(): void
    {
        $path = $this->ownedViews ?? null;
        $testingRoot = realpath(storage_path('framework/testing'));
        try {
            parent::tearDown();
        } finally {
            $resolved = $path ? realpath($path) : false;
            if ($testingRoot !== false && $resolved !== false
                && str_starts_with(strtolower($resolved), strtolower(rtrim($testingRoot, '\\/').DIRECTORY_SEPARATOR))) {
                (new Filesystem)->deleteDirectory($resolved);
            }
        }
    }

    public function test_google_login_is_offered_only_when_configured_and_the_real_redirect_uses_session_state(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(route('auth.google.redirect'), false);
        $this->get(route('register'))->assertOk()->assertSee(route('auth.google.redirect'), false);
        config(['services.google.client_secret' => '']);
        $this->get(route('login'))->assertOk()->assertDontSee(route('auth.google.redirect'), false);
        $this->get(route('auth.google.redirect'))->assertRedirect(route('login'))->assertSessionHasErrors('email');
        config(['services.google.client_secret' => 'fake-test-secret']);

        $response = $this->get(route('auth.google.redirect', ['role' => 'admin']))->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertSame('accounts.google.com', parse_url($location, PHP_URL_HOST));
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['state']);
        $this->assertSame(session('state'), $query['state']);
        $this->assertSame('http://localhost/auth/google/callback', $query['redirect_uri']);
        $this->assertSame('student', session(GoogleIdentity::CONTEXT)['role']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_real_socialite_state_mismatch_and_cancelled_or_expired_sessions_cannot_authenticate(): void
    {
        $stateWasRejected = false;
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('driver')->with('google')->andReturnUsing(function () use (&$stateWasRejected) {
            $provider = new GoogleProvider(request(), 'fake-client', 'fake-secret', 'http://localhost/auth/google/callback');
            $provider->setHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler([]))]));
            $proxy = Mockery::mock(Provider::class);
            $proxy->shouldReceive('user')->once()->andReturnUsing(function () use ($provider, &$stateWasRejected) {
                try {
                    return $provider->user();
                } catch (InvalidStateException $exception) {
                    $stateWasRejected = true;
                    throw $exception;
                }
            });

            return $proxy;
        });
        Socialite::swap($factory);
        $this->withSession(['state' => 'expected-state'])->get(route('auth.google.callback', [
            'state' => 'different-state', 'code' => 'untrusted-code',
        ]))->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertTrue($stateWasRejected);
        $this->assertNull(session('state'));
        $this->assertGuest();
        $this->get(route('auth.google.callback', ['error' => 'access_denied']))
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->withSession([GoogleIdentity::PENDING_REGISTRATION => $this->pending('expired', 'expired@example.test', -1)])
            ->get(route('auth.google.complete'))->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertNull(session(GoogleIdentity::PENDING_REGISTRATION));
        $this->assertNull(session(GoogleIdentity::PENDING_LINK));
    }

    public function test_unverified_or_invalid_google_profiles_and_provider_failures_do_not_create_accounts_or_expose_tokens(): void
    {
        foreach ([false, 'true', 1, null] as $verified) {
            $this->mockGoogle($this->googleUser('unverified', 'new@example.test', ['email_verified' => $verified]));
            $this->get(route('auth.google.callback', ['code' => 'fake-code']))->assertRedirect(route('login'))->assertSessionHasErrors('email')
                ->assertDontSee('PRIVATE_GOOGLE_ACCESS_TOKEN')->assertDontSee('PRIVATE_GOOGLE_REFRESH_TOKEN');
            $this->assertGuest();
            $this->assertNull(session(GoogleIdentity::PENDING_REGISTRATION));
        }
        $this->mockGoogle($this->googleUser('missing-email', '', ['email_verified' => true]));
        $this->get(route('auth.google.callback', ['code' => 'fake-code']))->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->mockGoogle(new RuntimeException('PRIVATE_PROVIDER_ERROR_TOKEN'));
        $this->get(route('auth.google.callback', ['code' => 'fake-code']))->assertRedirect(route('login'))->assertSessionHasErrors('email')
            ->assertDontSee('PRIVATE_PROVIDER_ERROR_TOKEN');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_new_student_completion_requires_terms_and_a_safe_role_and_trusts_only_the_verified_session_identity(): void
    {
        $this->mockGoogle($this->googleUser('new-student-id', 'STUDENT@Example.test', ['verified_email' => true]));
        $this->get(route('auth.google.callback', ['code' => 'fake-code']))->assertRedirect(route('auth.google.complete'));
        $pending = session(GoogleIdentity::PENDING_REGISTRATION);
        $this->assertSame('student@example.test', $pending['email']);
        $this->assertStringNotContainsString('PRIVATE_GOOGLE', json_encode($pending));
        $this->get(route('auth.google.complete'))->assertOk()->assertSee('student@example.test');
        $this->post(route('auth.google.store'), ['name' => 'Student', 'role' => 'admin', 'terms' => 1])
            ->assertSessionHasErrors('role');
        $this->post(route('auth.google.store'), ['name' => 'Student', 'role' => 'student'])
            ->assertSessionHasErrors('terms');
        $this->assertDatabaseCount('users', 0);
        $this->post(route('auth.google.store'), [
            'name' => 'Chosen Student Name', 'role' => 'student', 'terms' => 1,
            'email' => 'attacker@example.test', 'google_id' => 'attacker-id',
            'evaluator_status' => 'approved', 'is_suspended' => true,
        ])->assertSessionHasNoErrors()->assertRedirect(route('account.dashboard'));
        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Chosen Student Name', $user->name);
        $this->assertSame('student@example.test', $user->email);
        $this->assertSame('new-student-id', $user->google_id);
        $this->assertSame('student', $user->role);
        $this->assertSame('none', $user->evaluator_status);
        $this->assertFalse($user->is_suspended);
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->profile_photo_path);
        $this->assertArrayNotHasKey('google_id', $user->toArray());
        $this->assertStringNotContainsString('PRIVATE_GOOGLE', json_encode(DB::table('users')->get()));
        $this->assertNull(session(GoogleIdentity::PENDING_REGISTRATION));
    }

    public function test_new_google_evaluators_start_pending_and_a_matching_invitation_is_consumed_once(): void
    {
        $admin = User::factory()->admin()->create();
        $token = str_repeat('i', 48);
        $invitation = $this->invitation($admin, 'invited@example.test', $token);
        $this->mockGoogle($this->googleUser('invited-id', $invitation->email));
        $this->withSession([GoogleIdentity::CONTEXT => [
            'invite' => $token, 'role' => 'student', 'expires_at' => now()->addMinutes(10)->timestamp,
        ]])->get(route('auth.google.callback', ['code' => 'fake-code']))->assertRedirect(route('auth.google.complete'));
        $this->post(route('auth.google.store'), ['name' => 'Invited Expert', 'role' => 'student', 'terms' => 1])
            ->assertSessionHasNoErrors()->assertRedirect(route('account.evaluator-verification.show'));
        $user = User::where('google_id', 'invited-id')->sole();
        $this->assertSame('evaluator', $user->role);
        $this->assertSame('pending', $user->evaluator_status);
        $this->assertFalse($user->accepting_requests);
        $this->assertFalse($user->isEvaluator());
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertSame(1, $admin->activities()->where('title', 'New evaluator application')->count());
        $this->get(route('admin.index'))->assertForbidden();
        $this->post(route('logout'));
        $this->post(route('auth.google.store'), ['name' => 'Again', 'role' => 'evaluator', 'terms' => 1])
            ->assertRedirect(route('login'));
        $this->assertSame(1, User::where('google_id', 'invited-id')->count());

        $this->mockGoogle($this->googleUser('volunteer-id', 'volunteer@example.test'));
        $this->get(route('auth.google.callback', ['code' => 'fake-code']))->assertRedirect(route('auth.google.complete'));
        $this->post(route('auth.google.store'), ['name' => 'Volunteer Expert', 'role' => 'evaluator', 'terms' => 1])
            ->assertSessionHasNoErrors()->assertRedirect(route('account.evaluator-verification.show'));
        $volunteer = User::where('google_id', 'volunteer-id')->sole();
        $this->assertSame('evaluator', $volunteer->role);
        $this->assertSame('pending', $volunteer->evaluator_status);
        $this->assertFalse($volunteer->accepting_requests);
        $this->assertNull($volunteer->password);
    }

    public function test_invitation_email_mismatch_or_expiry_before_completion_never_creates_or_approves_a_google_account(): void
    {
        $admin = User::factory()->admin()->create();
        $token = str_repeat('x', 48);
        $invitation = $this->invitation($admin, 'invited@example.test', $token);
        $context = ['invite' => $token, 'role' => 'evaluator', 'expires_at' => now()->addMinutes(10)->timestamp];
        $this->mockGoogle($this->googleUser('wrong-invite', 'different@example.test'));
        $this->withSession([GoogleIdentity::CONTEXT => $context])->get(route('auth.google.callback', ['code' => 'fake-code']))
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->mockGoogle($this->googleUser('expiry-id', $invitation->email));
        $this->withSession([GoogleIdentity::CONTEXT => $context])->get(route('auth.google.callback', ['code' => 'fake-code']))
            ->assertRedirect(route('auth.google.complete'));
        $invitation->update(['expires_at' => now()->subSecond()]);
        $this->post(route('auth.google.store'), ['name' => 'Expert', 'role' => 'evaluator', 'terms' => 1])
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_existing_google_identity_signs_in_without_overwriting_roles_profile_or_password(): void
    {
        foreach (['student', 'evaluator', 'admin'] as $index => $role) {
            $user = User::factory()->create([
                'role' => $role, 'google_id' => 'existing-'.$index,
                'name' => 'Approved Local Name', 'evaluator_status' => $role === 'evaluator' ? 'approved' : 'none',
                'headline' => 'Saved local headline', 'expertise' => ['Database'],
            ]);
            $before = $user->fresh()->getAttributes();
            $this->mockGoogle($this->googleUser($user->google_id, 'changed-on-google-'.$index.'@example.test', ['email_verified' => true], 'Different Google Name'));
            $this->get(route('auth.google.callback', ['code' => 'fake-code']))->assertRedirect(route($role === 'admin' ? 'admin.index' : 'account.dashboard'));
            $this->assertAuthenticatedAs($user);
            $this->assertSame($before, $user->fresh()->getAttributes());
            $this->post(route('logout'));
        }
    }

    public function test_suspended_google_accounts_and_pending_links_cannot_bypass_the_existing_login_block(): void
    {
        foreach ([true, false] as $linked) {
            $user = User::factory()->create(['is_suspended' => true, 'google_id' => $linked ? 'suspended-id' : null]);
            $before = $user->fresh()->getAttributes();
            $this->mockGoogle($this->googleUser($linked ? 'suspended-id' : 'new-link-id', $user->email));
            $this->get(route('auth.google.callback', ['code' => 'fake-code']))->assertRedirect(route('login'))->assertSessionHasErrors('email');
            $this->assertGuest();
            $this->assertSame($before, $user->fresh()->getAttributes());
            $this->assertNull(session(GoogleIdentity::PENDING_LINK));
        }
    }

    public function test_matching_existing_email_is_not_merged_until_its_password_is_verified(): void
    {
        $user = User::factory()->evaluator()->create();
        $this->mockGoogle($this->googleUser('link-id', $user->email));
        $this->get(route('auth.google.callback', ['code' => 'fake-code']))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull($user->fresh()->google_id);
        $this->assertSame('link-id', session(GoogleIdentity::PENDING_LINK)['id']);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'incorrect'])
            ->assertSessionHasErrors('email');
        $this->assertNull($user->fresh()->google_id);
        $this->assertGuest();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasNoErrors()->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame('link-id', $user->fresh()->google_id);
        $this->assertSame('approved', $user->fresh()->evaluator_status);
        $this->assertNull(session(GoogleIdentity::PENDING_LINK));
        $this->assertSame(1, User::count());
    }

    public function test_other_accounts_expired_links_and_concurrent_identity_claims_cannot_attach_a_google_id(): void
    {
        $intended = User::factory()->create();
        $other = User::factory()->create();
        $this->withSession([GoogleIdentity::PENDING_LINK => $this->pending('private-link-id', $intended->email)])
            ->post(route('login'), ['email' => $other->email, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertNull($other->fresh()->google_id);
        $this->assertNull($intended->fresh()->google_id);
        $this->post(route('logout'));
        $this->withSession([GoogleIdentity::PENDING_LINK => $this->pending('expired-link-id', $intended->email, -1)])
            ->post(route('login'), ['email' => $intended->email, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertNull($intended->fresh()->google_id);
        $this->post(route('logout'));
        $claimed = User::factory()->create(['google_id' => 'claimed-id']);
        $this->withSession([GoogleIdentity::PENDING_LINK => $this->pending('claimed-id', $intended->email)])
            ->post(route('login'), ['email' => $intended->email, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($intended);
        $this->assertNull($intended->fresh()->google_id);
        $this->assertSame('claimed-id', $claimed->fresh()->google_id);
    }

    public function test_an_account_created_after_the_callback_is_not_duplicated_or_automatically_linked_at_completion(): void
    {
        $email = 'concurrent@example.test';
        $this->withSession([GoogleIdentity::PENDING_REGISTRATION => $this->pending('concurrent-id', $email)]);
        $existing = User::factory()->create(['email' => $email]);
        $this->post(route('auth.google.store'), ['name' => 'New Name', 'role' => 'student', 'terms' => 1])
            ->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull($existing->fresh()->google_id);
        $this->assertSame(1, User::count());
    }

    public function test_google_only_users_can_set_a_local_password_through_the_existing_reset_flow(): void
    {
        $user = User::factory()->create(['google_id' => 'passwordless-id', 'password' => null]);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'incorrect'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $profile = $this->actingAs($user)->get(route('account.profile'))->assertOk();
        $profile->assertSee(route('password.request'), false)->assertDontSee('name="current_password"', false);
        $this->get(route('password.request'))->assertOk()->assertSee($user->email);
        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
        $token = Password::broker()->createToken($user);
        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()->assertSee($user->email);
        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'NewLocalPassword!2026', 'password_confirmation' => 'NewLocalPassword!2026',
        ])->assertRedirect(route('login'))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('NewLocalPassword!2026', $user->fresh()->password));
        $this->assertSame('passwordless-id', $user->fresh()->google_id);
        $this->post(route('logout'));
        $this->post(route('login'), ['email' => $user->email, 'password' => 'NewLocalPassword!2026'])
            ->assertSessionHasNoErrors()->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_google_identity_migration_preserves_existing_accounts_and_refuses_to_remove_passwordless_accounts(): void
    {
        $user = User::factory()->evaluator()->create();
        $columns = ['id', 'name', 'email', 'password', 'role', 'evaluator_status', 'created_at', 'updated_at'];
        $before = (array) DB::table('users')->where('id', $user->id)->first($columns);
        $migration = require database_path('migrations/2026_10_31_000001_add_google_identity_to_users_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'google_id'));
        $this->assertSame($before, (array) DB::table('users')->where('id', $user->id)->first($columns));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('users', 'google_id'));
        $this->assertSame($before, (array) DB::table('users')->where('id', $user->id)->first($columns));
        $passwordless = User::factory()->create(['google_id' => 'migration-only-id', 'password' => null]);
        try {
            $migration->down();
            $this->fail('A rollback must not remove the only sign-in identity of a passwordless account.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no local password', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('users', 'google_id'));
        $this->assertSame('migration-only-id', $passwordless->fresh()->google_id);
        $this->assertNull($passwordless->fresh()->password);
        $this->assertSame($before, (array) DB::table('users')->where('id', $user->id)->first($columns));
    }

    private function googleUser(string $id, string $email, array $raw = ['email_verified' => true], string $name = 'Google Display Name'): GoogleUser
    {
        return (new GoogleUser)->setRaw($raw)->map(['id' => $id, 'email' => $email, 'name' => $name,
            'avatar' => 'https://example.test/google-avatar.png'])
            ->setToken('PRIVATE_GOOGLE_ACCESS_TOKEN')->setRefreshToken('PRIVATE_GOOGLE_REFRESH_TOKEN');
    }

    private function mockGoogle(GoogleUser|RuntimeException $result): void
    {
        $this->withSession([GoogleIdentity::CONTEXT => [
            'invite' => null, 'role' => 'student', 'expires_at' => now()->addMinutes(10)->timestamp,
        ]]);
        $provider = Mockery::mock(Provider::class);
        $expectation = $provider->shouldReceive('user')->once();
        $result instanceof RuntimeException ? $expectation->andThrow($result) : $expectation->andReturn($result);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('driver')->with('google')->andReturn($provider);
        Socialite::swap($factory);
    }

    private function pending(string $id, string $email, int $seconds = 600): array
    {
        return ['id' => $id, 'email' => $email, 'name' => 'Google Name', 'expires_at' => now()->addSeconds($seconds)->timestamp];
    }

    private function invitation(User $admin, string $email, string $token): Invitation
    {
        return Invitation::create(['email' => $email, 'invited_by' => $admin->id,
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
    }
}
