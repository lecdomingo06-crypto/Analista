<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsEvaluatorVerification;
use Tests\TestCase;

class ProfileRoleAccessTest extends TestCase
{
    use RefreshDatabase, BuildsEvaluatorVerification;

    public function test_student_and_admin_profiles_show_only_basic_controls(): void
    {
        foreach (['student', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $response = $this->actingAs($user)->get(route('account.profile'))->assertOk()
                ->assertSee('name="name"', false)->assertSee('name="city"', false)
                ->assertSee('type="email"', false)->assertSee('readonly', false)->assertSee($user->email);
            foreach (['headline', 'bio', 'expertise[]', 'skills', 'experience_years', 'capacity',
                'accepting_requests', 'review_modes[]', 'portfolio_url', 'apply', 'evidence_files[]', 'sample_review'] as $field) {
                $response->assertDontSee('name="'.$field.'"', false);
            }
            $response->assertDontSee('href="'.route('account.evaluator-verification.show').'"', false)
                ->assertDontSee('action="'.route('account.evaluator-verification.store').'"', false)
                ->assertDontSee('Become an evaluator')->assertDontSee('EVALUATOR APPLICATION');
        }
    }

    public function test_basic_profile_updates_preserve_roles_and_legacy_professional_attributes(): void
    {
        foreach (['student', 'admin'] as $role) {
            $user = User::factory()->create([
                'role' => $role, 'headline' => 'An existing professional headline retained for data history',
                'bio' => 'Existing biography that should not be erased when a basic profile is saved.',
                'expertise' => ['Database'], 'skills' => ['SQL'], 'experience_years' => 7,
                'capacity' => 4, 'review_modes' => ['online'], 'portfolio_url' => 'https://example.org/legacy-profile',
                'accepting_requests' => false, 'moderation_note' => 'An existing private moderation note.',
            ]);
            $keys = ['email', 'role', 'evaluator_status', 'is_suspended', 'headline', 'bio', 'expertise', 'skills',
                'experience_years', 'capacity', 'review_modes', 'portfolio_url', 'accepting_requests', 'moderation_note'];
            $preserved = $user->only($keys);
            $this->actingAs($user)->put(route('account.profile.update'), [
                'name' => 'Updated '.$role.' name', 'city' => 'Quezon City',
            ])->assertSessionHasNoErrors()->assertRedirect();

            $this->assertSame('Updated '.$role.' name', $user->fresh()->name);
            $this->assertSame('Quezon City', $user->fresh()->city);
            $this->assertSame($preserved, $user->fresh()->only($keys));
            $this->assertDatabaseCount('evaluator_verifications', 0);
        }
    }

    public function test_students_and_admins_cannot_submit_any_professional_or_application_field(): void
    {
        $professional = [
            'headline' => 'Crafted professional role',
            'bio' => 'A crafted professional biography that does not belong on this basic profile.',
            'expertise' => ['Web'], 'skills' => 'Laravel, MySQL', 'experience_years' => 9,
            'capacity' => 5, 'accepting_requests' => true, 'review_modes' => ['online'],
            'portfolio_url' => 'https://example.org/crafted-professional-profile', 'apply' => true,
        ];
        foreach (['student', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $snapshot = $user->only(['name', 'city', 'role', 'evaluator_status', ...array_keys($professional)]);
            foreach ($professional as $field => $value) {
                $this->actingAs($user)->putJson(route('account.profile.update'), [
                    'name' => $user->name, 'city' => 'Crafted change that must not be partially saved', $field => $value,
                ])->assertUnprocessable()->assertJsonValidationErrors($field);
                $this->assertSame($snapshot, $user->fresh()->only(array_keys($snapshot)));
            }
        }
        $this->assertDatabaseCount('evaluator_verifications', 0);
    }

    public function test_verification_pages_and_submissions_are_forbidden_for_students_and_admins(): void
    {
        foreach (['student', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('account.evaluator-verification.show'))->assertForbidden();
            $this->post(route('account.evaluator-verification.store'), $this->verificationData())->assertForbidden();
            $this->assertSame($role, $user->fresh()->role);
        }
        $this->assertDatabaseCount('evaluator_verifications', 0);
    }

    public function test_signed_in_students_and_admins_do_not_see_evaluator_conversion_actions(): void
    {
        foreach (['student', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user);
            foreach ([route('home'), route('evaluators.index')] as $url) {
                $this->get($url)->assertOk()->assertDontSee('Become an evaluator')
                    ->assertDontSee('Apply as an evaluator')->assertDontSee('Share your expertise')
                    ->assertDontSee('href="'.route('register', ['role' => 'evaluator']).'"', false);
            }
        }
    }

    public function test_evaluators_use_a_basic_profile_and_edit_professional_details_only_on_verification(): void
    {
        foreach (['pending', 'approved', 'rejected', 'needs_info'] as $status) {
            $user = User::factory()->evaluator()->create(['evaluator_status' => $status]);
            $profile = $this->actingAs($user)->get(route('account.profile'))->assertOk()
                ->assertSee('name="name"', false)->assertSee('name="city"', false)
                ->assertSee('href="'.route('account.evaluator-verification.show').'"', false);
            foreach (['headline', 'bio', 'expertise[]', 'skills', 'experience_years', 'review_modes[]', 'portfolio_url', 'apply'] as $field) {
                $profile->assertDontSee('name="'.$field.'"', false);
            }
            $profile->assertDontSee('VOLUNTEER AS AN EVALUATOR');
            if ($status === 'approved') {
                $profile->assertSee('name="capacity"', false)->assertSee('name="accepting_requests"', false);
            } else {
                $profile->assertDontSee('name="capacity"', false)->assertDontSee('name="accepting_requests"', false);
            }
            $this->get(route('account.evaluator-verification.show'))->assertOk()
                ->assertViewHas('canSubmit', true)
                ->assertSee('action="'.route('account.evaluator-verification.store').'"', false)
                ->assertSee('name="profile[headline]"', false)->assertSee('name="profile[bio]"', false)
                ->assertSee('name="profile[expertise][]"', false)->assertSee('name="profile[review_modes][]"', false);
        }
    }

    public function test_basic_evaluator_saves_preserve_professional_details_and_current_approval_status(): void
    {
        foreach (['pending', 'approved', 'rejected', 'needs_info'] as $status) {
            $user = User::factory()->evaluator()->create([
                'evaluator_status' => $status, 'accepting_requests' => $status === 'approved',
                'moderation_note' => 'Existing review note that must survive an ordinary city update.',
            ]);
            $snapshot = $user->evaluatorProfileSnapshot();
            $controls = $user->only(['role', 'evaluator_status', 'accepting_requests', 'capacity', 'moderation_note']);
            $this->actingAs($user)->from(route('account.profile'))->put(route('account.profile.update'), [
                'name' => $user->name, 'city' => 'Updated city',
            ])->assertSessionHasNoErrors()->assertRedirect(route('account.profile'));

            $this->assertSame('Updated city', $user->fresh()->city);
            $this->assertSame($snapshot, $user->fresh()->evaluatorProfileSnapshot());
            $this->assertSame($controls, $user->fresh()->only(array_keys($controls)));
        }
        $this->assertDatabaseCount('evaluator_verifications', 0);
    }

    public function test_every_evaluator_status_is_blocked_from_writing_professional_fields_on_my_profile(): void
    {
        $professional = [
            'headline' => 'A crafted professional headline', 'bio' => 'A sufficiently long crafted professional biography for this test.',
            'expertise' => ['Database'], 'skills' => 'SQL, Laravel', 'experience_years' => 8,
            'review_modes' => ['on-site'], 'portfolio_url' => 'https://example.org/crafted-profile', 'apply' => true,
        ];
        foreach (['pending', 'approved', 'rejected', 'needs_info'] as $status) {
            $user = User::factory()->evaluator()->create(['evaluator_status' => $status]);
            $snapshot = $user->evaluatorProfileSnapshot();
            foreach ($professional as $field => $value) {
                $this->actingAs($user)->putJson(route('account.profile.update'), [
                    'name' => $user->name, $field => $value,
                ])->assertUnprocessable()->assertJsonValidationErrors($field);
                $this->assertSame($snapshot, $user->fresh()->evaluatorProfileSnapshot());
                $this->assertSame($status, $user->fresh()->evaluator_status);
            }
        }
    }

    public function test_unapproved_evaluators_cannot_change_review_availability_or_capacity(): void
    {
        foreach (['pending', 'rejected', 'needs_info'] as $status) {
            $user = User::factory()->evaluator()->create(['evaluator_status' => $status, 'accepting_requests' => false]);
            $capacity = $user->capacity;
            $this->actingAs($user)->putJson(route('account.profile.update'), [
                'name' => $user->name, 'capacity' => 2, 'accepting_requests' => true,
            ])->assertUnprocessable()->assertJsonValidationErrors(['capacity', 'accepting_requests']);
            $this->assertSame($status, $user->fresh()->evaluator_status);
            $this->assertFalse($user->fresh()->accepting_requests);
            $this->assertSame($capacity, $user->fresh()->capacity);
        }
    }

    public function test_an_approved_evaluator_name_change_requires_fresh_verification_without_erasing_history(): void
    {
        User::factory()->admin()->create();
        $user = User::factory()->evaluator()->create(['accepting_requests' => true]);
        $approved = $this->createVerification($user, ['status' => 'approved']);
        $snapshot = $approved->profile_snapshot;
        $this->actingAs($user)->from(route('account.profile'))->put(route('account.profile.update'), [
            'name' => 'Updated evaluator display name', 'city' => 'Quezon City',
        ])->assertSessionHasNoErrors()->assertRedirect(route('account.profile'));

        $user->refresh();
        $this->assertSame('pending', $user->evaluator_status);
        $this->assertFalse($user->accepting_requests);
        $this->assertFalse($approved->matchesProfile($user));
        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame($snapshot, $approved->fresh()->profile_snapshot);
        $this->get(route('evaluators.show', $user))->assertNotFound();
        $this->get(route('account.evaluator-verification.show'))->assertOk()
            ->assertViewHas('canSubmit', true)->assertSee('name="profile[headline]"', false);
        $this->post(route('account.evaluator-verification.store'), $this->verificationData([
            'profile' => $this->professionalProfileData($user),
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $latest = $user->fresh()->latestEvaluatorVerification()->firstOrFail();
        $this->assertNotSame($approved->id, $latest->id);
        $this->assertSame('pending', $latest->status);
        $this->assertTrue($latest->matchesProfile($user->fresh()));
        $this->assertSame('approved', $approved->fresh()->status);
    }

    public function test_basic_profile_cannot_change_readonly_email_roles_or_other_privileged_fields(): void
    {
        foreach (['student', 'admin', 'evaluator'] as $role) {
            $user = $role === 'evaluator' ? User::factory()->evaluator()->create() : User::factory()->create(['role' => $role]);
            $snapshot = $user->only(['email', 'role', 'evaluator_status', 'is_suspended']);
            foreach (['email' => 'crafted-email@example.org', 'role' => 'admin', 'evaluator_status' => 'approved',
                'is_suspended' => true, 'user_id' => $user->id + 100, 'reviewer_id' => $user->id] as $field => $value) {
                $this->actingAs($user)->putJson(route('account.profile.update'), [
                    'name' => $user->name, $field => $value,
                ])->assertUnprocessable()->assertJsonValidationErrors($field);
                $this->assertSame($snapshot, $user->fresh()->only(array_keys($snapshot)));
            }
        }
    }

    public function test_evaluator_only_verification_still_blocks_duplicate_current_pending_evidence(): void
    {
        $user = User::factory()->pendingEvaluator()->create();
        $verification = $this->createVerification($user);
        $this->actingAs($user)->get(route('account.evaluator-verification.show'))->assertOk()
            ->assertViewHas('canSubmit', false)
            ->assertDontSee('class="panel stack verification-form"', false);
        $this->postJson(route('account.evaluator-verification.store'), $this->verificationData())
            ->assertUnprocessable()->assertJsonValidationErrors('verification');
        $this->assertSame('pending', $verification->fresh()->status);
        $this->assertDatabaseCount('evaluator_verifications', 1);
    }
}
