<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsEvaluatorVerification;
use Tests\TestCase;

class EvaluatorBadgeTest extends TestCase
{
    use BuildsEvaluatorVerification, RefreshDatabase;

    public function test_approved_account_without_evidence_has_a_generic_badge_and_no_sample_label(): void
    {
        $evaluator = User::factory()->evaluator()->create(['experience_years' => 0]);

        $this->assertFalse($evaluator->hasReviewedEvaluatorProfile());
        $this->assertPublicBadges($evaluator, 'Approved evaluator', 'Admin-reviewed evaluator');
        $this->get(route('home'))->assertOk()->assertSee('Volunteer evaluator');
        $this->get(route('evaluators.show', $evaluator))->assertOk()->assertSee('Experience not provided');
        $this->assertDatabaseCount('evaluator_verifications', 0);
    }

    public function test_approved_verification_matching_the_current_profile_keeps_the_reviewed_badge(): void
    {
        $evaluator = User::factory()->evaluator()->create();
        $admin = User::factory()->admin()->create();
        $this->createVerification($evaluator, [
            'status' => 'approved',
            'reviewer_id' => $admin->id,
            'reviewed_at' => now(),
            'checks' => ['identity' => true, 'expertise' => true, 'evidence' => true, 'feedback' => true],
        ]);

        $this->assertTrue($evaluator->hasReviewedEvaluatorProfile());
        $this->assertPublicBadges($evaluator, 'Admin-reviewed evaluator', 'Approved to participate in the Analista community.');
    }

    public function test_changed_professional_details_do_not_claim_the_current_profile_was_reviewed(): void
    {
        $evaluator = User::factory()->evaluator()->create();
        $this->createVerification($evaluator, ['status' => 'approved']);
        $evaluator->update(['headline' => 'Updated professional headline after the recorded review']);

        $this->assertTrue($evaluator->isEvaluator());
        $this->assertFalse($evaluator->hasReviewedEvaluatorProfile());
        $this->assertPublicBadges($evaluator, 'Approved evaluator', 'Admin-reviewed evaluator');
    }

    public function test_a_new_pending_submission_does_not_reuse_a_previous_approved_badge(): void
    {
        $evaluator = User::factory()->evaluator()->create();
        $this->createVerification($evaluator, ['status' => 'approved']);
        $this->assertTrue($evaluator->hasReviewedEvaluatorProfile());
        $this->createVerification($evaluator);
        $evaluator->refresh();

        $this->assertFalse($evaluator->hasReviewedEvaluatorProfile());
        $this->assertPublicBadges($evaluator, 'Approved evaluator', 'Admin-reviewed evaluator');
    }

    private function assertPublicBadges(User $evaluator, string $expected, string $unexpected): void
    {
        foreach ([route('home'), route('evaluators.index'), route('evaluators.show', $evaluator)] as $url) {
            $this->get($url)->assertOk()
                ->assertSee($evaluator->name)->assertSee($expected)->assertDontSee($unexpected)
                ->assertDontSee('Sample evaluator')->assertDontSee('Community preview account')
                ->assertDontSee('Auto-approved sample account');
        }
    }
}
