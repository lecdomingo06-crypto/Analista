<?php

namespace Tests\Feature;

use App\Models\EvaluatorEvidence;
use App\Models\EvaluatorVerification;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsEvaluatorVerification;
use Tests\TestCase;

class EvaluatorVerificationTest extends TestCase
{
    use RefreshDatabase, BuildsEvaluatorVerification;

    public function test_only_evaluator_applicants_can_submit_verification(): void
    {
        $this->post(route('account.evaluator-verification.store'), $this->verificationData())
            ->assertRedirect(route('login'));

        foreach ([User::factory()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->post(route('account.evaluator-verification.store'), $this->verificationData())
                ->assertForbidden();
        }

        $this->assertDatabaseCount('evaluator_verifications', 0);
    }

    public function test_document_evidence_is_stored_privately_with_an_immutable_profile_snapshot(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        User::factory()->admin()->create();
        $applicant = User::factory()->pendingEvaluator()->create(['accepting_requests' => false]);

        $this->actingAs($applicant)->post(route('account.evaluator-verification.store'), $this->verificationData([
            'evidence_type' => 'document',
            'evidence_files' => [UploadedFile::fake()->createWithContent('employment-proof.pdf', $this->pdf())],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $verification = EvaluatorVerification::sole();
        $evidence = EvaluatorEvidence::sole();
        $this->assertSame($applicant->id, $verification->user_id);
        $this->assertSame('pending', $verification->status);
        $this->assertSame($applicant->evaluatorProfileSnapshot(), $verification->profile_snapshot);
        $this->assertSame($applicant->evaluatorProfileFingerprint(), $verification->profile_fingerprint);
        $this->assertTrue($verification->attestation);
        $this->assertNull($verification->reviewer_id);
        $this->assertNull($verification->reviewed_at);
        $this->assertSame($verification->id, $evidence->evaluator_verification_id);
        $this->assertSame('employment-proof.pdf', $evidence->original_name);
        $this->assertSame('application/pdf', $evidence->mime_type);
        $this->assertNotSame($evidence->original_name, basename($evidence->path));
        Storage::disk('local')->assertExists($evidence->path);
        Storage::disk('public')->assertMissing($evidence->path);
        $this->assertFalse($applicant->fresh()->accepting_requests);
        $this->assertSame('pending', $applicant->fresh()->evaluator_status);
    }

    public function test_only_the_applicant_and_admin_can_download_private_evidence(): void
    {
        Storage::fake('local');
        $applicant = User::factory()->pendingEvaluator()->create();
        $verification = $this->createVerification($applicant, ['evidence_type' => 'document']);
        $path = 'verification-tests/private-proof.pdf';
        Storage::disk('local')->put($path, $this->pdf());
        $evidence = EvaluatorEvidence::create([
            'evaluator_verification_id' => $verification->id, 'path' => $path,
            'original_name' => 'private-proof.pdf', 'mime_type' => 'application/pdf', 'size' => strlen($this->pdf()),
        ]);

        $this->get(route('account.evaluator-evidence.download', $evidence))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('account.evaluator-evidence.download', $evidence))->assertForbidden();
        $this->actingAs(User::factory()->evaluator()->create())->get(route('account.evaluator-evidence.download', $evidence))->assertForbidden();
        $this->actingAs($applicant)->get(route('account.evaluator-evidence.download', $evidence))
            ->assertOk()->assertDownload('private-proof.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(User::factory()->admin()->create())->get(route('account.evaluator-evidence.download', $evidence))
            ->assertOk()->assertDownload('private-proof.pdf');
    }

    public function test_document_submissions_reject_disguised_files_oversize_uploads_and_too_many_files(): void
    {
        Storage::fake('local');
        $applicant = User::factory()->pendingEvaluator()->create();
        $this->actingAs($applicant);

        foreach (['not-a-pdf.pdf', 'not-a-photo.png'] as $name) {
            $this->postJson(route('account.evaluator-verification.store'), $this->verificationData([
                'evidence_type' => 'document',
                'evidence_files' => [UploadedFile::fake()->createWithContent($name, '<html>Disguised document content</html>')],
            ]))->assertUnprocessable()->assertJsonValidationErrors('evidence_files.0');
        }
        $this->postJson(route('account.evaluator-verification.store'), $this->verificationData([
            'evidence_type' => 'document', 'evidence_files' => [UploadedFile::fake()->create('oversize.pdf', 5121, 'application/pdf')],
        ]))->assertUnprocessable()->assertJsonValidationErrors('evidence_files.0');
        $files = [];
        for ($index = 0; $index < 4; $index++) {
            $files[] = UploadedFile::fake()->createWithContent('proof-'.$index.'.pdf', $this->pdf());
        }
        $this->postJson(route('account.evaluator-verification.store'), $this->verificationData([
            'evidence_type' => 'document', 'evidence_files' => $files,
        ]))->assertUnprocessable()->assertJsonValidationErrors('evidence_files');
        $this->postJson(route('account.evaluator-verification.store'), $this->verificationData([
            'evidence_type' => 'document',
        ]))->assertUnprocessable()->assertJsonValidationErrors('evidence_files');
        $this->assertDatabaseCount('evaluator_verifications', 0);
        $this->assertDatabaseCount('evaluator_evidence', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_png_and_jpeg_photographic_evidence_are_supported(): void
    {
        Storage::fake('local');
        $images = [
            'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aKAAAAABJRU5ErkJggg==',
            'jpg' => '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAb/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=',
        ];
        foreach ($images as $extension => $encoded) {
            $applicant = User::factory()->pendingEvaluator()->create();
            $this->actingAs($applicant)->post(route('account.evaluator-verification.store'), $this->verificationData([
                'evidence_type' => 'document', 'evidence_files' => [UploadedFile::fake()->createWithContent('credential.'.$extension, base64_decode($encoded))],
            ]))->assertSessionHasNoErrors()->assertRedirect();
            $verification = EvaluatorVerification::where('user_id', $applicant->id)->sole();
            $this->assertSame($extension === 'png' ? 'image/png' : 'image/jpeg', $verification->evidence()->sole()->mime_type);
        }
    }

    public function test_evidence_rejects_truncated_pdfs_and_valid_pdf_bytes_with_mismatched_extensions(): void
    {
        Storage::fake('local');
        $applicant = User::factory()->pendingEvaluator()->create();
        $this->actingAs($applicant);
        $files = [
            'truncated.pdf' => "%PDF-1.7\nnotcomplete",
            'document.html' => $this->pdf(),
            'document.php' => $this->pdf(),
            'document.png' => $this->pdf(),
        ];

        foreach ($files as $filename => $contents) {
            $this->postJson(route('account.evaluator-verification.store'), $this->verificationData([
                'evidence_type' => 'document',
                'evidence_files' => [UploadedFile::fake()->createWithContent($filename, $contents)],
            ]))->assertUnprocessable()->assertJsonValidationErrors('evidence_files.0');
        }

        $this->assertDatabaseCount('evaluator_verifications', 0);
        $this->assertDatabaseCount('evaluator_evidence', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_portfolio_and_reference_evidence_allow_nontraditional_professional_backgrounds(): void
    {
        foreach (['portfolio', 'reference'] as $type) {
            $applicant = User::factory()->pendingEvaluator()->create();
            $data = $this->verificationData(['background_type' => $type === 'portfolio' ? 'freelance' : 'academic', 'evidence_type' => $type]);
            if ($type === 'reference') {
                unset($data['evidence_url']);
                $data += ['reference_name' => 'Private Professional Referee', 'reference_email' => 'private-referee@example.org',
                    'reference_relationship' => 'Research supervisor and software project collaborator'];
            }
            $this->actingAs($applicant)->post(route('account.evaluator-verification.store'), $data)
                ->assertSessionHasNoErrors()->assertRedirect();
            $verification = EvaluatorVerification::where('user_id', $applicant->id)->sole();
            $this->assertSame($type, $verification->evidence_type);
            $this->assertSame($data['background_type'], $verification->background_type);
            $this->assertSame('pending', $verification->status);
            if ($type === 'reference') {
                $this->assertSame($data['reference_email'], $verification->reference_email);
            }
        }
        $this->assertDatabaseCount('evaluator_evidence', 0);
    }

    public function test_all_paths_require_work_samples_substantive_review_and_attestation(): void
    {
        $applicant = User::factory()->pendingEvaluator()->create();
        $data = $this->verificationData();
        unset($data['work_sample_url'], $data['work_sample_explanation'], $data['attestation']);
        $data['sample_review'] = 'Looks good.';
        $this->actingAs($applicant)->postJson(route('account.evaluator-verification.store'), $data)
            ->assertUnprocessable()->assertJsonValidationErrors(['work_sample_url', 'work_sample_explanation', 'sample_review', 'attestation']);
        $this->assertDatabaseCount('evaluator_verifications', 0);
    }

    public function test_portfolio_and_work_sample_links_must_use_http_or_https(): void
    {
        $applicant = User::factory()->pendingEvaluator()->create();
        $this->actingAs($applicant)->postJson(route('account.evaluator-verification.store'), $this->verificationData([
            'evidence_url' => 'javascript:alert(1)', 'work_sample_url' => 'file:///private/proof.pdf',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['evidence_url', 'work_sample_url']);
        $this->assertDatabaseCount('evaluator_verifications', 0);
    }

    public function test_reference_path_requires_contact_context_and_an_explanation(): void
    {
        $applicant = User::factory()->pendingEvaluator()->create();
        $this->actingAs($applicant)->postJson(route('account.evaluator-verification.store'), $this->verificationData([
            'evidence_type' => 'reference', 'reference_email' => 'not-an-email', 'evidence_explanation' => 'Too brief.',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['reference_name', 'reference_email', 'reference_relationship', 'evidence_explanation']);
        $this->assertDatabaseCount('evaluator_verifications', 0);
    }

    public function test_applicants_cannot_name_themselves_as_their_professional_reference(): void
    {
        $applicant = User::factory()->pendingEvaluator()->create();
        $this->actingAs($applicant)->postJson(route('account.evaluator-verification.store'), $this->verificationData([
            'evidence_type' => 'reference', 'reference_name' => $applicant->name,
            'reference_email' => strtoupper($applicant->email),
            'reference_relationship' => 'My own professional assessment',
        ]))->assertUnprocessable()->assertJsonValidationErrors('reference_email');
        $this->assertDatabaseCount('evaluator_verifications', 0);
    }

    public function test_an_incomplete_professional_profile_cannot_submit_evidence(): void
    {
        $applicant = User::factory()->pendingEvaluator()->create(['headline' => null, 'bio' => null, 'expertise' => []]);
        $this->actingAs($applicant)->postJson(route('account.evaluator-verification.store'), $this->verificationData())
            ->assertUnprocessable();
        $this->assertDatabaseCount('evaluator_verifications', 0);
    }

    public function test_applicants_cannot_override_snapshot_approval_review_or_ownership_fields(): void
    {
        $applicant = User::factory()->pendingEvaluator()->create();
        $admin = User::factory()->admin()->create();
        $this->actingAs($applicant)->post(route('account.evaluator-verification.store'), $this->verificationData([
            'user_id' => $admin->id, 'status' => 'approved', 'evaluator_status' => 'approved', 'role' => 'admin',
            'reviewer_id' => $admin->id, 'reviewed_at' => now()->toDateTimeString(), 'review_notes' => 'Applicant-written approval.',
            'profile_snapshot' => ['name' => 'Forged applicant'], 'profile_fingerprint' => 'forged',
            'checks' => ['identity' => true, 'expertise' => true, 'evidence' => true, 'feedback' => true],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $verification = EvaluatorVerification::sole();
        $this->assertSame($applicant->id, $verification->user_id);
        $this->assertSame('pending', $verification->status);
        $this->assertSame($applicant->evaluatorProfileFingerprint(), $verification->profile_fingerprint);
        $this->assertNull($verification->reviewer_id);
        $this->assertNull($verification->reviewed_at);
        $this->assertNull($verification->review_notes);
        $this->assertEmpty($verification->checks);
        $this->assertSame('evaluator', $applicant->fresh()->role);
        $this->assertFalse($applicant->fresh()->isEvaluator());
    }

    public function test_a_current_pending_submission_cannot_be_duplicated(): void
    {
        $applicant = User::factory()->pendingEvaluator()->create();
        $this->actingAs($applicant)->post(route('account.evaluator-verification.store'), $this->verificationData())
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->postJson(route('account.evaluator-verification.store'), $this->verificationData())->assertUnprocessable();
        $this->assertDatabaseCount('evaluator_verifications', 1);
    }

    public function test_admin_approval_requires_a_current_complete_submission_checks_and_a_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $applicant = User::factory()->pendingEvaluator()->create();
        $this->actingAs($admin)->postJson(route('admin.evaluators.moderate', $applicant), [
            'decision' => 'approve', 'reason' => 'Reviewed the available profile information.',
            'checks' => ['identity' => true, 'expertise' => true, 'evidence' => true, 'feedback' => true],
        ])->assertUnprocessable()->assertJsonValidationErrors('verification_id');

        $verification = $this->createVerification($applicant);
        foreach (['identity', 'expertise', 'evidence', 'feedback'] as $check) {
            $data = $this->approvalData($verification);
            $data['checks'][$check] = false;
            $this->postJson(route('admin.evaluators.moderate', $applicant), $data)
                ->assertUnprocessable()->assertJsonValidationErrors('checks.'.$check);
            $this->assertSame('pending', $applicant->fresh()->evaluator_status);
        }
        foreach (['approve', 'needs_info', 'reject'] as $decision) {
            $data = $this->approvalData($verification, ['decision' => $decision]);
            unset($data['reason']);
            $this->postJson(route('admin.evaluators.moderate', $applicant), $data)
                ->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        $this->post(route('admin.evaluators.moderate', $applicant), $this->approvalData($verification))
            ->assertSessionHasNoErrors()->assertRedirect();
        $verification->refresh();
        $this->assertSame('approved', $verification->status);
        $this->assertSame($admin->id, $verification->reviewer_id);
        $this->assertNotNull($verification->reviewed_at);
        $this->assertSame($this->approvalData($verification)['reason'], $verification->review_notes);
        $this->assertTrue($applicant->fresh()->isEvaluator());
    }

    public function test_another_applicants_or_obsolete_submission_cannot_be_approved(): void
    {
        $admin = User::factory()->admin()->create();
        $applicant = User::factory()->pendingEvaluator()->create();
        $other = User::factory()->pendingEvaluator()->create();
        $otherProof = $this->createVerification($other);
        $oldProof = $this->createVerification($applicant);
        $latestProof = $this->createVerification($applicant);
        $this->actingAs($admin);
        foreach ([$otherProof, $oldProof] as $proof) {
            $this->postJson(route('admin.evaluators.moderate', $applicant), $this->approvalData($proof))
                ->assertUnprocessable()->assertJsonValidationErrors('verification_id');
        }
        $this->assertSame('pending', $latestProof->fresh()->status);
        $this->assertFalse($applicant->fresh()->isEvaluator());
    }

    public function test_incomplete_or_stale_verification_cannot_be_approved(): void
    {
        $admin = User::factory()->admin()->create();
        $applicant = User::factory()->pendingEvaluator()->create();
        $incomplete = $this->createVerification($applicant, ['sample_review' => 'Too brief.']);
        $this->actingAs($admin)->postJson(route('admin.evaluators.moderate', $applicant), $this->approvalData($incomplete))
            ->assertUnprocessable()->assertJsonValidationErrors('decision');

        $current = $this->createVerification($applicant);
        $snapshot = $current->profile_snapshot;
        $applicant->update(['headline' => 'Updated professional background requiring another evidence submission']);
        $this->postJson(route('admin.evaluators.moderate', $applicant), $this->approvalData($current))
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->assertSame($snapshot, $current->fresh()->profile_snapshot);
        $this->assertFalse($applicant->fresh()->isEvaluator());
    }

    public function test_requests_for_information_and_rejections_allow_new_submissions_without_erasing_history(): void
    {
        $admin = User::factory()->admin()->create();
        $applicant = User::factory()->pendingEvaluator()->create();
        $first = $this->createVerification($applicant);
        $firstSnapshot = $first->profile_snapshot;

        $this->actingAs($admin)->post(route('admin.evaluators.moderate', $applicant), $this->approvalData($first, [
            'decision' => 'needs_info', 'reason' => 'Please explain your individual contribution to the linked work sample.',
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('needs_info', $first->fresh()->status);
        $this->actingAs($applicant)->post(route('account.evaluator-verification.store'), $this->verificationData([
            'work_sample_explanation' => 'I personally designed the relational schema, reviewed the application architecture, and created the end-to-end evaluation tasks for the linked sample.',
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $second = EvaluatorVerification::where('user_id', $applicant->id)->latest('id')->firstOrFail();
        $this->assertNotSame($first->id, $second->id);

        $this->actingAs($admin)->post(route('admin.evaluators.moderate', $applicant), $this->approvalData($second, [
            'decision' => 'reject', 'reason' => 'The evidence does not yet demonstrate the expertise needed for this review role.',
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('rejected', $second->fresh()->status);
        $this->actingAs($applicant)->post(route('account.evaluator-verification.store'), $this->verificationData())
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('evaluator_verifications', 3);
        $this->assertSame($firstSnapshot, $first->fresh()->profile_snapshot);
        $this->assertSame('needs_info', $first->fresh()->status);
        $this->assertSame('rejected', $second->fresh()->status);
        $this->assertSame('pending', EvaluatorVerification::latest('id')->firstOrFail()->status);
    }

    public function test_replacing_approved_evidence_returns_the_evaluator_to_pending_review(): void
    {
        $applicant = User::factory()->evaluator()->create();
        $approved = $this->createVerification($applicant, ['status' => 'approved']);
        $this->actingAs($applicant)->post(route('account.evaluator-verification.store'), $this->verificationData())
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame('pending', EvaluatorVerification::latest('id')->firstOrFail()->status);
        $this->assertSame('pending', $applicant->fresh()->evaluator_status);
        $this->assertFalse($applicant->fresh()->accepting_requests);
        $this->get(route('evaluators.show', $applicant))->assertNotFound();
    }

    public function test_report_corrections_and_hiding_close_pending_verification_and_allow_a_new_submission(): void
    {
        $admin = User::factory()->admin()->create();
        $reporter = User::factory()->create();

        foreach (['request_corrections' => 'needs_info', 'hide_content' => 'rejected'] as $action => $status) {
            $applicant = User::factory()->pendingEvaluator()->create();
            $old = $this->createVerification($applicant);
            $snapshot = $old->profile_snapshot;
            $report = Report::create([
                'user_id' => $reporter->id, 'reportable_type' => 'user', 'reportable_id' => $applicant->id,
                'reason' => 'fake_information', 'details' => 'Please check the stated professional background.', 'status' => 'pending',
            ]);
            $note = 'Please replace the evidence and explain which professional contributions are your own.';

            $this->actingAs($admin)->post(route('admin.reports.moderate', $report), [
                'status' => 'resolved', 'action' => $action, 'admin_note' => $note,
            ])->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame($status, $applicant->fresh()->evaluator_status);
            $this->assertFalse($applicant->fresh()->accepting_requests);
            $this->assertSame($status, $old->fresh()->status);
            $this->assertSame($admin->id, $old->fresh()->reviewer_id);
            $this->assertNotNull($old->fresh()->reviewed_at);
            $this->assertSame($note, $old->fresh()->review_notes);
            $this->assertSame($snapshot, $old->fresh()->profile_snapshot);
            $activity = $applicant->activities()->where('title', 'Your evaluator profile needs attention')->sole();
            $this->assertSame(route('account.evaluator-verification.show'), $activity->url);

            $this->actingAs($applicant->fresh())->get(route('account.evaluator-verification.show'))
                ->assertOk()->assertViewHas('canSubmit', true)->assertSee($note);
            $this->post(route('account.evaluator-verification.store'), $this->verificationData())
                ->assertSessionHasNoErrors()->assertRedirect();
            $latest = $applicant->fresh()->latestEvaluatorVerification()->firstOrFail();
            $this->assertNotSame($old->id, $latest->id);
            $this->assertSame('pending', $latest->status);
            $this->assertSame($status, $old->fresh()->status);

            $this->actingAs($admin)->postJson(route('admin.evaluators.moderate', $applicant), $this->approvalData($old))
                ->assertUnprocessable()->assertJsonValidationErrors('verification_id');
            $this->assertSame('pending', $latest->fresh()->status);
        }
    }

    public function test_report_corrections_preserve_approved_history_but_hide_the_profile_and_show_a_new_form(): void
    {
        $admin = User::factory()->admin()->create();
        $applicant = User::factory()->pendingEvaluator()->create();
        $approved = $this->createVerification($applicant);
        $this->actingAs($admin)->post(route('admin.evaluators.moderate', $applicant), $this->approvalData($approved))
            ->assertSessionHasNoErrors()->assertRedirect();
        $original = $approved->fresh();
        $report = Report::create([
            'user_id' => User::factory()->create()->id, 'reportable_type' => 'user', 'reportable_id' => $applicant->id,
            'reason' => 'fake_information', 'details' => 'The professional evidence needs another check.', 'status' => 'pending',
        ]);
        $note = 'Please clarify the ownership of the linked project and provide updated evidence.';

        $this->post(route('admin.reports.moderate', $report), [
            'status' => 'resolved', 'action' => 'request_corrections', 'admin_note' => $note,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('needs_info', $applicant->fresh()->evaluator_status);
        $this->assertFalse($applicant->fresh()->accepting_requests);
        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame($original->review_notes, $approved->fresh()->review_notes);
        $this->assertSame($original->reviewer_id, $approved->fresh()->reviewer_id);
        $this->assertTrue($original->reviewed_at->equalTo($approved->fresh()->reviewed_at));
        $this->assertSame($original->checks, $approved->fresh()->checks);
        $this->get(route('evaluators.index'))->assertOk()
            ->assertViewHas('evaluators', fn ($evaluators) => ! $evaluators->contains('id', $applicant->id));
        $this->get(route('home'))->assertOk()
            ->assertViewHas('reviewers', fn ($reviewers) => ! $reviewers->contains('id', $applicant->id));
        $this->get(route('evaluators.show', $applicant))->assertNotFound();

        $this->actingAs($applicant->fresh())->get(route('account.evaluator-verification.show'))
            ->assertOk()->assertViewHas('canSubmit', true)->assertSee('Current admin note')->assertSee($note)
            ->assertSee('class="panel stack verification-form"', false)
            ->assertDontSee('Replace my evidence and request a new review');
        $this->post(route('account.evaluator-verification.store'), $this->verificationData())
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame('pending', $applicant->fresh()->latestEvaluatorVerification()->firstOrFail()->status);
    }

    public function test_material_profile_changes_require_a_new_submission_while_old_snapshots_stay_immutable(): void
    {
        $admin = User::factory()->admin()->create();
        $applicant = User::factory()->evaluator()->create();
        $old = $this->createVerification($applicant, ['status' => 'approved']);
        $snapshot = $old->profile_snapshot;
        $fingerprint = $old->profile_fingerprint;
        $this->actingAs($applicant)->get(route('account.evaluator-verification.show'))->assertOk()
            ->assertViewHas('profileComplete', true)->assertViewHas('canSubmit', true)
            ->assertSee('name="profile[headline]"', false)->assertSee('name="profile[bio]"', false)
            ->assertSee('name="profile[expertise][]"', false)->assertSee('name="profile[review_modes][]"', false);
        $this->post(route('account.evaluator-verification.store'), $this->verificationData([
            'profile' => $this->professionalProfileData($applicant, ['review_modes' => ['online', 'on-site']]),
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $applicant->refresh();
        $this->assertSame('pending', $applicant->evaluator_status);
        $this->assertFalse($applicant->accepting_requests);
        $this->assertFalse($old->matchesProfile($applicant));
        $this->assertSame($snapshot, $old->fresh()->profile_snapshot);
        $this->assertSame($fingerprint, $old->fresh()->profile_fingerprint);
        $this->get(route('evaluators.show', $applicant))->assertNotFound();

        $new = EvaluatorVerification::where('user_id', $applicant->id)->latest('id')->firstOrFail();
        $this->assertNotSame($old->id, $new->id);
        $this->assertNotSame($fingerprint, $new->profile_fingerprint);
        $this->assertTrue($new->matchesProfile($applicant));
        $this->assertSame('approved', $old->fresh()->status);
        $this->actingAs($admin)->post(route('admin.evaluators.moderate', $applicant), $this->approvalData($new))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($applicant->fresh()->isEvaluator());
    }

    public function test_verification_dossier_is_admin_only_and_never_leaks_into_public_profiles(): void
    {
        $admin = User::factory()->admin()->create();
        $applicant = User::factory()->pendingEvaluator()->create();
        $verification = $this->createVerification($applicant, [
            'organization' => 'PRIVATE ORGANIZATION EVIDENCE ONLY',
            'reference_name' => 'PRIVATE REFERENCE NAME', 'reference_email' => 'private-reference-only@example.org',
            'sample_review' => 'PRIVATE SAMPLE RESPONSE. '.str_repeat('Explain participant selection and objective-specific evaluation methods. ', 4),
        ]);

        $this->get(route('admin.evaluators.show', $applicant))->assertRedirect(route('login'));
        $this->actingAs($applicant)->get(route('admin.evaluators.show', $applicant))->assertForbidden();
        $this->actingAs(User::factory()->evaluator()->create())->get(route('admin.evaluators.show', $applicant))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.evaluators.show', $applicant))->assertOk()
            ->assertSee($verification->organization)->assertSee('PRIVATE SAMPLE RESPONSE.');
        $this->post(route('admin.evaluators.moderate', $applicant), $this->approvalData($verification))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('logout'));
        foreach ([route('home'), route('evaluators.index'), route('evaluators.show', $applicant)] as $url) {
            $response = $this->get($url)->assertOk();
            foreach ([$verification->organization, 'PRIVATE SAMPLE RESPONSE.', $verification->reference_email,
                $verification->evidence_url, $verification->work_sample_url, $verification->profile_fingerprint] as $secret) {
                $response->assertDontSee($secret);
            }
        }
    }

    public function test_verification_test_fixtures_are_confined_to_the_isolated_database(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('evaluator_verifications', 0);
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    }
}
