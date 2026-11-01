<?php

namespace Tests\Feature;

use App\Models\EvaluatorEvidence;
use App\Models\EvaluatorVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsEvaluatorVerification;
use Tests\TestCase;

class InlineEvaluatorProfileTest extends TestCase
{
    use RefreshDatabase, BuildsEvaluatorVerification;

    public function test_new_evaluator_registration_lands_on_the_visible_proof_form(): void
    {
        $this->post(route('register'), [
            'name' => 'New Volunteer Applicant', 'email' => 'new-proof-applicant@example.org',
            'password' => 'ProofApplication!2026', 'password_confirmation' => 'ProofApplication!2026',
            'role' => 'evaluator', 'terms' => true,
        ])->assertSessionHasNoErrors()->assertRedirect(route('account.evaluator-verification.show'));

        $this->assertAuthenticatedAs(User::where('email', 'new-proof-applicant@example.org')->sole());
        $this->get(route('account.evaluator-verification.show'))->assertOk()
            ->assertViewHas('profileComplete', false)->assertViewHas('canSubmit', true)
            ->assertSee('id="submit-evidence"', false)->assertSee('name="evidence_files[]"', false)
            ->assertSee('name="profile[headline]"', false)
            ->assertSee('href="#supporting-evidence"', false);
    }

    public function test_incomplete_evaluators_immediately_see_profile_fields_and_proofs_in_one_form(): void
    {
        $user = $this->incompleteEvaluator();
        $response = $this->actingAs($user)->get(route('account.evaluator-verification.show'))->assertOk()
            ->assertViewHas('profileComplete', false)->assertViewHas('canSubmit', true)
            ->assertSee('id="submit-evidence"', false)
            ->assertSee('action="'.route('account.evaluator-verification.store').'"', false)
            ->assertSee('name="evidence_files[]"', false)->assertSee('name="sample_review"', false)
            ->assertDontSee('action="'.route('account.profile.update').'"', false)
            ->assertDontSee('href="#complete-professional-profile"', false)
            ->assertDontSee('Save and continue to proofs');

        foreach (['name', 'headline', 'bio', 'expertise][]', 'skills', 'experience_years',
            'capacity', 'review_modes][]', 'portfolio_url', 'city'] as $field) {
            $name = str_contains($field, '][]') ? 'profile['.$field : 'profile['.$field.']';
            $response->assertSee('name="'.$name.'"', false);
        }
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $proofInputs = $xpath->query('//form[@id="submit-evidence"]//input[@name="evidence_files[]"]');
        $this->assertSame(1, $proofInputs->length);
        $this->assertSame(0, $xpath->query('ancestor-or-self::*[@hidden]', $proofInputs->item(0))->length);
        $this->assertDatabaseCount('evaluator_verifications', 0);
    }

    public function test_one_submission_atomically_saves_the_professional_profile_and_private_document_proof(): void
    {
        Storage::fake('local');
        $user = $this->incompleteEvaluator();
        $original = $user->only(['city', 'skills', 'portfolio_url']);
        $this->actingAs($user)->post(route('account.evaluator-verification.store'), $this->verificationData([
            'profile' => $this->completedProfile($user), 'evidence_type' => 'document',
            'evidence_files' => [UploadedFile::fake()->createWithContent('professional-proof.pdf', $this->pdf())],
        ]))->assertSessionHasNoErrors()->assertRedirect(route('account.evaluator-verification.show'));

        $user->refresh();
        $verification = EvaluatorVerification::sole();
        $evidence = EvaluatorEvidence::sole();
        $this->assertSame($original, $user->only(array_keys($original)));
        $this->assertSame('Software engineer and volunteer database reviewer', $user->headline);
        $this->assertSame(['Web', 'Database'], $user->expertise);
        $this->assertSame(['online'], $user->review_modes);
        $this->assertSame('evaluator', $user->role);
        $this->assertSame('pending', $user->evaluator_status);
        $this->assertFalse($user->accepting_requests);
        $this->assertSame($user->id, $verification->user_id);
        $this->assertSame('pending', $verification->status);
        $this->assertSame($user->evaluatorProfileSnapshot(), $verification->profile_snapshot);
        $this->assertSame($user->evaluatorProfileFingerprint(), $verification->profile_fingerprint);
        $this->assertSame($verification->id, $evidence->evaluator_verification_id);
        Storage::disk('local')->assertExists($evidence->path);
        $this->assertTrue($verification->isComplete());
        $this->get(route('account.evaluator-verification.show'))->assertOk()
            ->assertViewHas('profileComplete', true)->assertViewHas('canSubmit', false);
    }

    public function test_optional_existing_profile_details_are_preserved_when_not_in_the_combined_submission(): void
    {
        $user = $this->incompleteEvaluator();
        $profile = $this->completedProfile($user);
        unset($profile['city'], $profile['skills'], $profile['portfolio_url']);
        $original = $user->only(['city', 'skills', 'portfolio_url']);
        $this->actingAs($user)->post(route('account.evaluator-verification.store'), $this->verificationData([
            'profile' => $profile,
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($original, $user->fresh()->only(array_keys($original)));
        $this->assertSame($user->fresh()->evaluatorProfileSnapshot(), EvaluatorVerification::sole()->profile_snapshot);
    }

    public function test_invalid_inline_profile_returns_old_values_and_never_partially_saves_profile_or_proof(): void
    {
        Storage::fake('local');
        $user = $this->incompleteEvaluator();
        $original = $this->profileSnapshot($user);
        $profile = $this->completedProfile($user);
        $profile['headline'] = 'A headline entered beside the proof fields';
        $profile['bio'] = 'Too brief.';
        $this->actingAs($user)->from(route('account.evaluator-verification.show'))
            ->post(route('account.evaluator-verification.store'), $this->verificationData([
                'profile' => $profile, 'evidence_type' => 'document',
                'evidence_files' => [UploadedFile::fake()->createWithContent('professional-proof.pdf', $this->pdf())],
            ]))->assertRedirect(route('account.evaluator-verification.show'))
            ->assertSessionHasErrors('profile.bio')
            ->assertSessionHasInput('profile.headline', $profile['headline'])
            ->assertSessionHasInput('profile.bio', $profile['bio']);

        $this->assertSame($original, $this->profileSnapshot($user->fresh()));
        $this->assertNoSubmissionArtifacts();
        $this->get(route('account.evaluator-verification.show'))->assertOk()
            ->assertViewHas('canSubmit', true)->assertSee('id="submit-evidence"', false)
            ->assertSee($profile['headline'])->assertSee($profile['bio'])
            ->assertSee('name="evidence_files[]"', false);
    }

    public function test_invalid_proofs_do_not_partially_save_a_valid_inline_profile(): void
    {
        Storage::fake('local');
        $user = $this->incompleteEvaluator();
        $original = $this->profileSnapshot($user);
        $profile = $this->completedProfile($user);
        $this->actingAs($user)->from(route('account.evaluator-verification.show'))
            ->post(route('account.evaluator-verification.store'), $this->verificationData([
                'profile' => $profile, 'sample_review' => 'Too short.',
            ]))->assertRedirect(route('account.evaluator-verification.show'))
            ->assertSessionHasErrors('sample_review')
            ->assertSessionHasInput('profile.headline', $profile['headline']);
        $this->assertSame($original, $this->profileSnapshot($user->fresh()));
        $this->assertNoSubmissionArtifacts();

        $this->postJson(route('account.evaluator-verification.store'), $this->verificationData([
            'profile' => $profile, 'evidence_type' => 'document',
            'evidence_files' => [UploadedFile::fake()->createWithContent('forged.pdf', '<html>Not a PDF</html>')],
        ]))->assertUnprocessable()->assertJsonValidationErrors('evidence_files.0');
        $this->assertSame($original, $this->profileSnapshot($user->fresh()));
        $this->assertNoSubmissionArtifacts();
    }

    public function test_nested_profile_cannot_override_roles_approval_or_another_applicant(): void
    {
        Storage::fake('local');
        $user = $this->incompleteEvaluator();
        $admin = User::factory()->admin()->create();
        $original = $this->profileSnapshot($user);
        $profile = $this->completedProfile($user) + [
            'role' => 'admin', 'evaluator_status' => 'approved', 'is_suspended' => false,
            'user_id' => $admin->id, 'reviewer_id' => $admin->id,
        ];
        $this->actingAs($user)->postJson(route('account.evaluator-verification.store'), $this->verificationData([
            'profile' => $profile,
        ]))->assertUnprocessable()->assertJsonValidationErrors('profile');

        $this->assertSame($original, $this->profileSnapshot($user->fresh()));
        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertNoSubmissionArtifacts();
    }

    private function incompleteEvaluator(): User
    {
        return User::factory()->pendingEvaluator()->create([
            'headline' => null, 'bio' => 'Short biography.', 'expertise' => [], 'review_modes' => [],
            'city' => 'Quezon City', 'skills' => ['Laravel', 'MySQL'],
            'portfolio_url' => 'https://example.org/existing-professional-portfolio',
            'accepting_requests' => false,
        ]);
    }

    private function completedProfile(User $user): array
    {
        return [
            'name' => $user->name, 'headline' => 'Software engineer and volunteer database reviewer',
            'bio' => 'I help student teams improve database relationships, document design decisions, and plan useful evaluations.',
            'expertise' => ['Web', 'Database'], 'city' => $user->city, 'skills' => implode(', ', $user->skills),
            'experience_years' => $user->experience_years, 'capacity' => $user->capacity,
            'portfolio_url' => $user->portfolio_url, 'review_modes' => ['online'],
        ];
    }

    private function profileSnapshot(User $user): array
    {
        return $user->only(['name', 'headline', 'bio', 'expertise', 'city', 'skills', 'experience_years',
            'capacity', 'portfolio_url', 'review_modes', 'role', 'evaluator_status', 'accepting_requests']);
    }

    private function assertNoSubmissionArtifacts(): void
    {
        $this->assertDatabaseCount('evaluator_verifications', 0);
        $this->assertDatabaseCount('evaluator_evidence', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    }
}
