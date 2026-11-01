<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsEvaluatorVerification;
use Tests\TestCase;

class RealEvaluatorDirectoryTest extends TestCase
{
    use RefreshDatabase, BuildsEvaluatorVerification;

    public function test_sample_account_seeding_is_refused_in_local_environment_before_creating_users(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertDatabaseCount('users', 0);
        $previousEnvironment = $this->app->environment();

        try {
            $this->app->instance('env', 'local');
            $this->seed(DemoSeeder::class);
            $this->fail('Local environments must not install sample community accounts.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('test fixtures only', $exception->getMessage());
        } finally {
            $this->app->instance('env', $previousEnvironment);
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('tutorials', 0);
    }

    public function test_normal_database_seeding_never_creates_evaluator_accounts_or_directory_listings(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::where('role', 'evaluator')->count());
        $this->get(route('evaluators.index'))->assertOk()
            ->assertViewHas('evaluators', fn ($evaluators) => $evaluators->total() === 0);
        $this->get(route('home'))->assertOk()
            ->assertViewHas('reviewers', fn ($reviewers) => $reviewers->isEmpty())
            ->assertViewHas('stats', fn ($stats) => $stats['evaluators'] === 0);
    }

    public function test_real_evaluator_signup_requires_complete_profile_and_admin_approval_before_listing(): void
    {
        $admin = User::factory()->admin()->create();
        $name = 'Community Professional Applicant';
        $this->post(route('register'), [
            'name' => $name,
            'email' => 'community-applicant@example.org',
            'password' => 'CommunityExpert!2026',
            'password_confirmation' => 'CommunityExpert!2026',
            'role' => 'evaluator',
            'terms' => '1',
        ])->assertRedirect(route('account.evaluator-verification.show'))->assertSessionHasNoErrors();

        $evaluator = User::where('email', 'community-applicant@example.org')->firstOrFail();
        $this->assertAuthenticatedAs($evaluator);
        $this->assertSame('pending', $evaluator->evaluator_status);
        $this->assertFalse($evaluator->accepting_requests);
        $this->assertHiddenFromDirectory($evaluator);

        $this->actingAs($admin)->post(route('admin.evaluators.moderate', $evaluator), ['decision' => 'approve'])
            ->assertSessionHasErrors('verification_id');
        $this->assertSame('pending', $evaluator->fresh()->evaluator_status);

        $this->actingAs($evaluator)->post(route('account.evaluator-verification.store'), $this->verificationData(['profile' => [
            'name' => $name,
            'headline' => 'Software engineer and community thesis reviewer',
            'city' => 'Quezon City',
            'bio' => 'I volunteer to help student teams improve system design, database relationships, and practical testing plans.',
            'expertise' => ['Web', 'Database'],
            'skills' => 'Laravel, MySQL, Software testing',
            'experience_years' => 5,
            'capacity' => 2,
            'review_modes' => ['online'],
            'portfolio_url' => 'https://example.org/community-professional',
        ]]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('pending', $evaluator->fresh()->evaluator_status);
        $this->assertFalse($evaluator->fresh()->accepting_requests);
        $this->assertHiddenFromDirectory($evaluator);

        $verification = $evaluator->fresh()->latestEvaluatorVerification()->firstOrFail();
        $this->actingAs($admin)->post(route('admin.evaluators.moderate', $evaluator), $this->approvalData($verification))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $evaluator->fresh()->evaluator_status);
        $this->assertTrue($evaluator->fresh()->accepting_requests);
        $this->post(route('logout'))->assertRedirect();

        $this->get(route('evaluators.index'))->assertOk()->assertSee($name)
            ->assertViewHas('evaluators', fn ($evaluators) => $evaluators->contains('id', $evaluator->id));
        $this->get(route('evaluators.show', $evaluator))->assertOk()->assertSee($name);
        $this->get(route('home'))->assertOk()
            ->assertViewHas('reviewers', fn ($reviewers) => $reviewers->contains('id', $evaluator->id))
            ->assertViewHas('stats', fn ($stats) => $stats['evaluators'] === 1);

        $this->actingAs($evaluator->fresh())->post(route('account.evaluator-verification.store'), $this->verificationData())
            ->assertRedirect()->assertSessionHasNoErrors();
        $replacement = $evaluator->fresh()->latestEvaluatorVerification()->firstOrFail();
        $this->actingAs($admin)->post(route('admin.evaluators.moderate', $evaluator), $this->approvalData($replacement, [
            'decision' => 'reject',
            'reason' => 'Professional credentials require further verification before this profile can be listed.',
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('rejected', $evaluator->fresh()->evaluator_status);
        $this->assertHiddenFromDirectory($evaluator);
    }

    public function test_suspended_approved_evaluators_are_removed_from_public_directory_profile_and_homepage(): void
    {
        $admin = User::factory()->admin()->create();
        $evaluator = User::factory()->evaluator()->create();

        $this->get(route('evaluators.index'))->assertOk()
            ->assertViewHas('evaluators', fn ($evaluators) => $evaluators->contains('id', $evaluator->id));
        $this->actingAs($admin)->post(route('admin.users.toggle', $evaluator), ['action' => 'suspend'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($evaluator->fresh()->is_suspended);
        $this->assertSame('approved', $evaluator->fresh()->evaluator_status);
        $this->assertHiddenFromDirectory($evaluator);
    }

    private function assertHiddenFromDirectory(User $evaluator): void
    {
        $this->get(route('evaluators.index'))->assertOk()
            ->assertViewHas('evaluators', fn ($evaluators) => ! $evaluators->contains('id', $evaluator->id));
        $this->get(route('evaluators.show', $evaluator))->assertNotFound();
        $this->get(route('home'))->assertOk()
            ->assertViewHas('reviewers', fn ($reviewers) => ! $reviewers->contains('id', $evaluator->id))
            ->assertViewHas('stats', fn ($stats) => $stats['evaluators'] === 0);
    }
}
