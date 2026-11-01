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

class VerificationSubmissionStateTest extends TestCase
{
    use BuildsEvaluatorVerification, RefreshDatabase;

    public function test_a_successful_application_replaces_upload_controls_with_saved_details_and_review_guidance(): void
    {
        Storage::fake('local');
        $user = User::factory()->pendingEvaluator()->create();
        $profile = $this->professionalProfileData($user, [
            'name' => 'Saved application reviewer',
            'headline' => 'Application architecture and database reviewer',
            'bio' => 'I review application architecture and relational database design, and help students explain and test their technical decisions.',
            'experience_years' => 7,
            'expertise' => ['Web', 'Database'],
            'skills' => 'PHP, PostgreSQL, System design',
            'review_modes' => ['online', 'on-site'],
            'portfolio_url' => 'https://example.org/submitted-professional-profile',
        ]);
        $data = $this->verificationData([
            'profile' => $profile, 'evidence_type' => 'document',
            'evidence_files' => [UploadedFile::fake()->createWithContent('saved-professional-proof.pdf', $this->pdf())],
        ]);

        $this->actingAs($user)->post(route('account.evaluator-verification.store'), $data)
            ->assertSessionHasNoErrors()->assertRedirect(route('account.evaluator-verification.show'))
            ->assertSessionHas('success', 'Your application has been submitted and is under review.');

        $user->refresh();
        $verification = EvaluatorVerification::sole();
        $evidence = EvaluatorEvidence::sole();
        $this->assertSame('pending', $verification->status);
        $this->assertSame($user->evaluatorProfileSnapshot(), $verification->profile_snapshot);
        $this->assertSame($user->evaluatorProfileFingerprint(), $verification->profile_fingerprint);
        $this->assertSame($verification->id, $evidence->evaluator_verification_id);
        Storage::disk('local')->assertExists($evidence->path);

        // The second visit has no submission flash: the saved state must still explain what happened.
        for ($visit = 0; $visit < 2; $visit++) {
            $response = $this->get(route('account.evaluator-verification.show'))->assertOk()
                ->assertViewHas('profileCurrent', true)->assertViewHas('canSubmit', false)
                ->assertSee('Your application is under review')
                ->assertSee('Your professional details, evidence, and practice review have been saved.')
                ->assertSee('Estimated review time: 24–48 hours. Reviews may take longer depending on volunteer availability.')
                ->assertSee('Your evaluator profile stays hidden until approval.')
                ->assertDontSee('id="submit-evidence"', false)
                ->assertDontSee('name="evidence_files[]"', false)
                ->assertDontSee('Upload professional proof');

            $xpath = $this->xpath($response->getContent());
            $this->assertSame(1, $xpath->query('//section[@id="application-status"]//h2[text()="Your application is under review"]')->length);
            $notificationLink = $xpath->query('//section[@id="application-status"]//a[contains(., "Check Notifications for the decision")]')->item(0);
            $this->assertNotNull($notificationLink);
            $this->assertSame(route('account.dashboard', ['tab' => 'notifications']), $notificationLink->getAttribute('href'));
            $history = $xpath->query('//section[@id="submission-history"]')->item(0);
            $this->assertNotNull($history);
            foreach ([$profile['name'], $profile['headline'], $profile['bio'], 'Web, Database',
                'PHP, PostgreSQL, System design', 'Online, On-site', $profile['portfolio_url'],
                $data['organization'], $data['background_summary'], $data['work_sample_explanation'],
                $data['sample_review'], $evidence->original_name] as $savedText) {
                $this->assertStringContainsString($savedText, $history->textContent);
            }
            $this->assertSame('7', trim($xpath->query('//section[@id="submission-history"]//dt[text()="Years of experience"]/following-sibling::dd')->item(0)->textContent));
            $this->assertSame(0, $xpath->query('.//input | .//textarea | .//select', $history)->length);
            $this->assertSame(route('account.evaluator-evidence.download', $evidence), $xpath->query('.//a[contains(., "saved-professional-proof.pdf")]', $history)->item(0)->getAttribute('href'));
        }

        $this->get(route('account.evaluator-evidence.download', $evidence))->assertOk()
            ->assertDownload('saved-professional-proof.pdf');
        $this->assertDatabaseCount('evaluator_verifications', 1);
    }

    public function test_validation_keeps_text_nested_profile_and_selections_without_partially_saving_the_application(): void
    {
        Storage::fake('local');
        $user = User::factory()->pendingEvaluator()->create([
            'headline' => null, 'bio' => 'Incomplete.', 'expertise' => [], 'review_modes' => [],
        ]);
        $original = $user->fresh()->getAttributes();
        $profile = $this->professionalProfileData($user, [
            'name' => 'Unsaved reviewer name', 'city' => 'Unsaved application city',
            'headline' => 'Unsaved architecture reviewer headline',
            'bio' => 'This unsaved biography describes my database design work and constructive approach to student review.',
            'experience_years' => 9, 'capacity' => 5,
            'expertise' => ['Database', 'Web'], 'skills' => 'SQL, PHP, Research methods',
            'review_modes' => ['on-site', 'online'],
            'portfolio_url' => 'https://example.org/unsaved-portfolio',
        ]);
        $data = $this->verificationData([
            'profile' => $profile, 'background_type' => 'freelance',
            'organization' => 'Unsaved independent practice', 'sample_review' => 'Too brief.',
            'evidence_type' => 'document',
            'evidence_files' => [UploadedFile::fake()->createWithContent('select-again.pdf', $this->pdf())],
        ]);
        $this->actingAs($user)->from(route('account.evaluator-verification.show'))
            ->post(route('account.evaluator-verification.store'), $data)
            ->assertRedirect(route('account.evaluator-verification.show'))
            ->assertSessionHasErrors('sample_review')
            ->assertSessionHasInput('profile', $profile)
            ->assertSessionHasInput('background_type', 'freelance')
            ->assertSessionHasInput('evidence_type', 'document')
            ->assertSessionHasInput('organization', $data['organization']);

        $response = $this->get(route('account.evaluator-verification.show'))->assertOk()
            ->assertViewHas('canSubmit', true)
            ->assertSee('Your application has not been submitted yet.')
            ->assertSee('Your text entries are kept below. If you selected supporting documents, choose those files again before submitting.')
            ->assertDontSee('Your application is under review');
        $xpath = $this->xpath($response->getContent());
        $form = $xpath->query('//form[@id="submit-evidence"]')->item(0);
        $this->assertNotNull($form);
        foreach (['name', 'city', 'headline', 'skills', 'portfolio_url', 'experience_years', 'capacity'] as $field) {
            $this->assertSame((string) $profile[$field], $xpath->query('.//input[@name="profile['.$field.']"]', $form)->item(0)->getAttribute('value'));
        }
        $this->assertSame($profile['bio'], $xpath->query('.//textarea[@name="profile[bio]"]', $form)->item(0)->textContent);
        foreach (['organization', 'work_sample_url'] as $field) {
            $this->assertSame($data[$field], $xpath->query('.//input[@name="'.$field.'"]', $form)->item(0)->getAttribute('value'));
        }
        foreach (['background_summary', 'work_sample_explanation', 'sample_review'] as $field) {
            $this->assertSame($data[$field], $xpath->query('.//textarea[@name="'.$field.'"]', $form)->item(0)->textContent);
        }
        foreach (['expertise', 'review_modes'] as $field) {
            $checked = $xpath->query('.//input[@name="profile['.$field.'][]"][@checked]', $form);
            $actual = [];
            foreach ($checked as $input) {
                $actual[] = $input->getAttribute('value');
            }
            $this->assertEqualsCanonicalizing($profile[$field], $actual);
        }
        $this->assertSame('freelance', $xpath->query('.//select[@name="background_type"]/option[@selected]', $form)->item(0)->getAttribute('value'));
        $this->assertSame('document', $xpath->query('.//input[@name="evidence_type"][@checked]', $form)->item(0)->getAttribute('value'));
        $this->assertSame(1, $xpath->query('.//input[@name="attestation"][@checked]', $form)->length);
        $this->assertSame('', $xpath->query('.//input[@name="evidence_files[]"]', $form)->item(0)->getAttribute('value'));

        $this->assertSame($original, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('evaluator_verifications', 0);
        $this->assertDatabaseCount('evaluator_evidence', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_changed_pending_profile_can_submit_a_replacement_without_erasing_its_previous_details(): void
    {
        $user = User::factory()->pendingEvaluator()->create(['name' => 'Original submitted reviewer']);
        $original = $this->createVerification($user);
        $snapshot = $original->profile_snapshot;
        $this->actingAs($user)->get(route('account.evaluator-verification.show'))->assertOk()
            ->assertViewHas('canSubmit', false)->assertSee('Your application is under review');

        $this->from(route('account.profile'))->put(route('account.profile.update'), [
            'name' => 'Corrected reviewer display name',
        ])->assertSessionHasNoErrors()->assertRedirect(route('account.profile'));
        $user->refresh();
        $this->get(route('account.evaluator-verification.show'))->assertOk()
            ->assertViewHas('profileCurrent', false)->assertViewHas('canSubmit', true)
            ->assertSee('Your professional profile has changed since this submission.')
            ->assertSee('id="submit-evidence"', false)->assertSee('Original submitted reviewer')
            ->assertDontSee('Your application is under review');

        $this->post(route('account.evaluator-verification.store'), $this->verificationData([
            'profile' => $this->professionalProfileData($user),
        ]))->assertSessionHasNoErrors()->assertRedirect(route('account.evaluator-verification.show'))
            ->assertSessionHas('success', 'Your application has been submitted and is under review.');

        $user->refresh();
        $replacement = $user->latestEvaluatorVerification()->firstOrFail();
        $this->assertNotSame($original->id, $replacement->id);
        $this->assertSame('pending', $replacement->status);
        $this->assertTrue($replacement->matchesProfile($user));
        $this->assertSame($snapshot, $original->fresh()->profile_snapshot);
        $this->assertSame('pending', $original->fresh()->status);
        $this->assertDatabaseCount('evaluator_verifications', 2);
        $this->get(route('account.evaluator-verification.show'))->assertOk()
            ->assertViewHas('profileCurrent', true)->assertViewHas('canSubmit', false)
            ->assertSee('Your application is under review')->assertSee('Original submitted reviewer')
            ->assertSee('Corrected reviewer display name')->assertSee('Replaced by a newer submission')
            ->assertDontSee('id="submit-evidence"', false)->assertDontSee('Upload professional proof');
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new \DOMXPath($document);
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    }
}
