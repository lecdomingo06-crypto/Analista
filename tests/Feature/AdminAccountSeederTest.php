<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminAccountSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    public function test_explicit_seeding_creates_the_admin_and_reruns_preserve_existing_accounts(): void
    {
        $existing = User::factory()->create(['name' => 'Existing member']);
        $original = $existing->refresh()->getAttributes();

        (new AdminAccountSeeder)->run();

        $admin = User::where('username', 'admin')->sole();
        $this->assertSame('Admin', $admin->name);
        $this->assertSame('admin-login@analista.test', $admin->email);
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue(Hash::check('Admin', $admin->password));
        $this->assertNotSame('Admin', $admin->getRawOriginal('password'));
        $this->assertSame($original, $existing->fresh()->getAttributes());

        $admin->update([
            'name' => 'Updated administrator',
            'password' => 'Changed administrator password',
            'is_suspended' => true,
            'remember_token' => 'existing-remember-token',
        ]);
        $changed = $admin->fresh()->getAttributes();

        (new AdminAccountSeeder)->run();

        $this->assertDatabaseCount('users', 2);
        $this->assertSame($changed, $admin->fresh()->getAttributes());
        $this->assertSame($original, $existing->fresh()->getAttributes());
        $this->assertTrue(Hash::check('Changed administrator password', $admin->fresh()->password));
    }

    #[DataProvider('conflictingAccounts')]
    public function test_seeding_refuses_conflicts_without_changing_any_accounts(array $accounts): void
    {
        foreach ($accounts as $attributes) {
            User::factory()->create($attributes);
        }
        $original = DB::table('users')->orderBy('id')->get()->map(fn ($account) => (array) $account)->all();

        try {
            (new AdminAccountSeeder)->run();
            $this->fail('An existing login must never authorize replacing or promoting an account.');
        } catch (LogicException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertSame($original, DB::table('users')->orderBy('id')->get()->map(fn ($account) => (array) $account)->all());
    }

    public static function conflictingAccounts(): array
    {
        return [
            'email already belongs to a member' => [[[
                'email' => 'admin-login@analista.test',
            ]]],
            'normalized username already belongs to a member' => [[[
                'email' => 'member@example.test', 'username' => ' AdMiN ',
            ]]],
            'email and username belong to different accounts' => [[
                ['email' => 'admin-login@analista.test'],
                ['email' => 'other-admin@example.test', 'username' => 'admin', 'role' => 'admin'],
            ]],
            'both identifiers belong to a non-admin account' => [[[
                'email' => 'admin-login@analista.test', 'username' => 'admin',
            ]]],
        ];
    }

    public function test_default_seeding_creates_no_accounts_and_production_refuses_the_admin_seeder(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('users', 0);

        $previous = $this->app->environment();
        try {
            $this->app->instance('env', 'production');
            (new AdminAccountSeeder)->run();
            $this->fail('Production must not receive the explicitly requested local admin credentials.');
        } catch (LogicException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        } finally {
            $this->app->instance('env', $previous);
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_can_use_the_requested_username_or_email_with_normalized_case(): void
    {
        (new AdminAccountSeeder)->run();
        $admin = User::where('username', 'admin')->sole();
        $this->get(route('login'))->assertOk()->assertSee('type="text" name="email"', false);

        foreach (['Admin', '  aDmIn  ', 'ADMIN-LOGIN@ANALISTA.TEST'] as $identifier) {
            $this->post(route('login'), ['email' => $identifier, 'password' => 'Admin'])
                ->assertSessionHasNoErrors()->assertRedirect(route('admin.index'));
            $this->assertAuthenticatedAs($admin);
            $this->get(route('admin.index'))->assertOk();
            $this->post(route('logout'));
            $this->assertGuest();
        }
    }

    public function test_unknown_usernames_display_names_and_suspended_admins_cannot_sign_in(): void
    {
        (new AdminAccountSeeder)->run();
        User::factory()->admin()->create([
            'name' => 'DisplayNameOnly', 'email' => 'display-name@example.test', 'password' => 'Admin',
        ]);

        foreach (['missing-username', 'DisplayNameOnly'] as $identifier) {
            $this->post(route('login'), ['email' => $identifier, 'password' => 'Admin'])
                ->assertSessionHasErrors('email');
            $this->assertGuest();
        }

        User::where('username', 'admin')->sole()->update(['is_suspended' => true]);
        foreach (['Admin', 'admin-login@analista.test'] as $identifier) {
            $this->post(route('login'), ['email' => $identifier, 'password' => 'Admin'])
                ->assertSessionHasErrors(['email' => 'Your account is suspended. Please contact the administrator.']);
            $this->assertGuest();
        }
    }

    public function test_students_and_evaluators_require_email_and_registration_cannot_assign_a_username(): void
    {
        foreach (['student' => 'studentuser', 'evaluator' => 'evaluser'] as $role => $username) {
            $user = User::factory()->create([
                'role' => $role, 'username' => $username,
                'email' => $username.'@example.test', 'password' => 'Working password',
            ]);

            $this->post(route('login'), ['email' => $username, 'password' => 'Working password'])
                ->assertSessionHasErrors('email');
            $this->assertGuest();
            $this->post(route('login'), ['email' => $user->email, 'password' => 'Working password'])
                ->assertSessionHasNoErrors()->assertRedirect(route('account.dashboard'));
            $this->assertAuthenticatedAs($user);
            $this->post(route('logout'));
        }

        $this->post(route('register'), [
            'name' => 'New member', 'email' => 'new-member@example.test', 'username' => 'admin',
            'password' => 'New member password', 'password_confirmation' => 'New member password',
            'role' => 'student', 'terms' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('account.dashboard'));
        $this->assertNull(User::where('email', 'new-member@example.test')->sole()->username);
    }

    public function test_username_and_email_share_the_same_five_attempt_login_limit(): void
    {
        (new AdminAccountSeeder)->run();
        $key = 'login:admin-login@analista.test|127.0.0.1';

        foreach (['Admin', 'admin-login@analista.test', 'ADMIN', 'ADMIN-LOGIN@ANALISTA.TEST', 'Admin'] as $identifier) {
            $this->post(route('login'), ['email' => $identifier, 'password' => 'incorrect'])
                ->assertSessionHasErrors('email');
            $this->assertGuest();
        }
        $this->assertSame(5, RateLimiter::attempts($key));

        foreach (['Admin', 'admin-login@analista.test'] as $identifier) {
            $this->post(route('login'), ['email' => $identifier, 'password' => 'Admin'])
                ->assertSessionHasErrors('email');
            $this->assertStringContainsString('Please try again in ', session('errors')->first('email'));
            $this->assertGuest();
        }

        $this->travel(61)->seconds();
        $this->post(route('login'), ['email' => 'Admin', 'password' => 'Admin'])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.index'));
        $this->assertSame(0, RateLimiter::attempts($key));
        $this->assertAuthenticatedAs(User::where('username', 'admin')->sole());
    }
}
