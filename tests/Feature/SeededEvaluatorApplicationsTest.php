<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\EvaluatorEvidence;
use App\Models\EvaluatorVerification;
use App\Models\User;
use Database\Seeders\AdditionalEvaluatorApplicationsSeeder;
use Database\Seeders\AdditionalEvaluatorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Tests\Support\BuildsEvaluatorVerification;
use Tests\TestCase;

class SeededEvaluatorApplicationsTest extends TestCase
{
    use BuildsEvaluatorVerification, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_accounts_receive_complete_approved_applications_and_public_reviewed_badges_without_private_evidence(): void
    {
        User::factory()->admin()->create(['is_suspended' => true]);
        $admin = User::factory()->admin()->create();
        $accounts = new AdditionalEvaluatorSeeder;
        $accounts->run();

        $this->assertCount(3, $accounts->credentials());
        $this->assertDatabaseCount('evaluator_verifications', 3);
        $this->assertDatabaseCount('evaluator_evidence', 3);
        $this->assertDatabaseCount('admin_actions', 3);
        $this->assertDatabaseCount('ratings', 0);
        $this->assertDatabaseCount('projects', 0);

        foreach (EvaluatorVerification::with(['user', 'evidence'])->get() as $application) {
            $evaluator = $application->user;
            $this->assertTrue($evaluator->isEvaluator());
            $this->assertTrue($evaluator->hasReviewedEvaluatorProfile());
            $this->assertSame('approved', $application->status);
            $this->assertTrue($application->isComplete());
            $this->assertTrue($application->matchesProfile($evaluator));
            $this->assertSame($evaluator->evaluatorProfileSnapshot(), $application->profile_snapshot);
            $this->assertSame($admin->id, $application->reviewer_id);
            $this->assertNotNull($application->reviewed_at);
            $this->assertSame(array_fill_keys(array_keys(EvaluatorVerification::REVIEW_CHECKS), true), $application->checks);
            $this->assertMatchesRegularExpression('/seed|development/i', $application->review_notes);
            $this->assertMatchesRegularExpression('/simulat/i', $application->review_notes);

            $evidence = $application->evidence->sole();
            $pdf = Storage::disk('local')->get($evidence->path);
            $this->assertSame('application/pdf', $evidence->mime_type);
            $this->assertSame(strlen($pdf), $evidence->size);
            $this->assertSame('application/pdf', (new \finfo(FILEINFO_MIME_TYPE))->buffer($pdf));
            $this->assertStringStartsWith('%PDF-', $pdf);
            $this->assertStringContainsString('/Type /Catalog', $pdf);
            $this->assertStringContainsString('/Type /Page', $pdf);
            $this->assertStringContainsString('xref', $pdf);
            $this->assertMatchesRegularExpression('/%%EOF\s*$/', $pdf);
            $this->assertMatchesRegularExpression('/development|synthetic/i', $pdf);
            $this->assertSame(route('account.evaluator-evidence.download', $evidence), $application->work_sample_url);
            Storage::disk('public')->assertMissing($evidence->path);

            $audit = AdminAction::where('target_id', $evaluator->id)->sole();
            $this->assertSame($admin->id, $audit->actor_id);
            $this->assertSame('evaluator.approved', $audit->action);
            $this->assertSame($application->id, $audit->metadata['verification_id']);
            $this->assertMatchesRegularExpression('/seed|development/i', $audit->reason);
            $this->assertMatchesRegularExpression('/simulat/i', $audit->reason);

            foreach ([route('home'), route('evaluators.index'), route('evaluators.show', $evaluator)] as $url) {
                $response = $this->get($url)->assertOk()->assertSee($evaluator->name)->assertSee('Admin-reviewed evaluator');
                foreach ([$application->background_summary, $application->sample_review, $application->review_notes,
                    $application->work_sample_url, $application->profile_fingerprint, $evidence->path, $evidence->original_name] as $private) {
                    $response->assertDontSee($private);
                }
            }
        }
    }

    public function test_seeded_pdf_download_is_private_and_available_only_to_its_owner_and_an_active_admin(): void
    {
        $admin = User::factory()->admin()->create();
        (new AdditionalEvaluatorSeeder)->run();
        $evidence = EvaluatorEvidence::with('verification.user')->firstOrFail();
        $url = route('account.evaluator-evidence.download', $evidence);

        $this->get($url)->assertRedirect(route('login'));
        foreach ([User::factory()->create(), User::factory()->evaluator()->create()] as $unrelated) {
            $this->actingAs($unrelated)->get($url)->assertForbidden();
        }
        foreach ([$evidence->verification->user, $admin] as $authorized) {
            $response = $this->actingAs($authorized)->get($url)->assertOk()
                ->assertDownload($evidence->original_name)->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertSame(Storage::disk('local')->get($evidence->path), $response->streamedContent());
        }
    }

    public function test_reruns_preserve_existing_users_application_history_audits_and_files(): void
    {
        User::factory()->admin()->create();
        (new AdditionalEvaluatorSeeder)->run();
        $evaluators = User::where('role', 'evaluator')->orderBy('id')->get();
        $this->createVerification($evaluators[0], ['status' => 'pending']);
        $this->createVerification($evaluators[1], ['status' => 'rejected', 'review_notes' => 'An existing rejection must be preserved.']);
        $evaluators[2]->update(['headline' => 'A changed profile with an older approved application']);
        $realEvaluator = User::factory()->evaluator()->create();
        $this->createVerification($realEvaluator, ['status' => 'approved']);
        User::factory()->create();
        $before = $this->databaseSnapshot();
        $files = $this->fileSnapshot();

        $accounts = new AdditionalEvaluatorSeeder;
        $accounts->run();
        $applications = new AdditionalEvaluatorApplicationsSeeder;
        $applications->run();

        $this->assertSame([], $accounts->credentials());
        $this->assertSame([], $applications->createdApplicationIds());
        $this->assertSame($before, $this->databaseSnapshot());
        $this->assertSame($files, $this->fileSnapshot());
    }

    public function test_application_seeding_adds_a_dossier_without_changing_the_account_or_credentials(): void
    {
        User::factory()->admin()->create();
        $evaluator = $this->directSeededEvaluator();
        $before = $evaluator->refresh()->getAttributes();
        $applications = new AdditionalEvaluatorApplicationsSeeder;
        $applications->run();

        $this->assertSame($before, $evaluator->fresh()->getAttributes());
        $this->assertSame([$evaluator->evaluatorVerifications()->sole()->id], $applications->createdApplicationIds());
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('evaluator_verifications', 1);
        $this->assertDatabaseCount('activities', 0);
    }

    public function test_application_seeding_skips_moderated_edited_incomplete_and_unrelated_accounts(): void
    {
        User::factory()->admin()->create();
        $evaluator = $this->directSeededEvaluator();
        $original = $evaluator->getAttributes();
        $unrelated = User::factory()->evaluator()->create();
        $unrelatedBefore = $unrelated->refresh()->getAttributes();
        foreach ([
            ['is_suspended' => true],
            ['evaluator_status' => 'pending'],
            ['evaluator_status' => 'rejected'],
            ['headline' => null],
            ['bio' => 'This account owner has replaced the seeded biography with their own professional description.'],
            ['moderation_note' => 'An administrator reviewed and changed this account.'],
        ] as $change) {
            $evaluator->setRawAttributes($original);
            $evaluator->fill($change)->save();
            $before = $evaluator->refresh()->getAttributes();
            $applications = new AdditionalEvaluatorApplicationsSeeder;
            $applications->run();

            $this->assertSame([], $applications->createdApplicationIds());
            $this->assertSame($before, $evaluator->fresh()->getAttributes());
            $this->assertSame($unrelatedBefore, $unrelated->fresh()->getAttributes());
            $this->assertDatabaseCount('evaluator_verifications', 0);
            $this->assertDatabaseCount('admin_actions', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_a_demoted_account_is_preserved_and_cannot_receive_an_approved_application(): void
    {
        User::factory()->admin()->create();
        $evaluator = $this->directSeededEvaluator();
        $evaluator->update(['role' => 'student']);
        $before = $evaluator->refresh()->getAttributes();

        try {
            (new AdditionalEvaluatorApplicationsSeeder)->run();
            $this->fail('A reserved email cannot authorize approval after an account changes role.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('already belongs to another account', $exception->getMessage());
        }

        $this->assertSame($before, $evaluator->fresh()->getAttributes());
        $this->assertDatabaseCount('evaluator_verifications', 0);
        $this->assertDatabaseCount('admin_actions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_audit_failure_rolls_back_accounts_applications_and_only_new_files_without_exposing_credentials(): void
    {
        $admin = User::factory()->admin()->create();
        Storage::disk('local')->put('existing-private-file.pdf', 'Keep this existing file.');
        $dispatcher = AdminAction::getEventDispatcher();
        AdminAction::setEventDispatcher(clone $dispatcher);
        $auditAttempts = 0;
        AdminAction::creating(function () use (&$auditAttempts): void {
            if (++$auditAttempts === 2) {
                throw new RuntimeException('Simulated audit persistence failure.');
            }
        });
        $accounts = new AdditionalEvaluatorSeeder;

        try {
            $accounts->run();
            $this->fail('A failed audit must roll back the complete account-seeding operation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated audit persistence failure.', $exception->getMessage());
        } finally {
            AdminAction::setEventDispatcher($dispatcher);
        }

        $this->assertSame([], $accounts->credentials());
        $this->assertSame([$admin->id], User::pluck('id')->all());
        $this->assertDatabaseCount('evaluator_verifications', 0);
        $this->assertDatabaseCount('evaluator_evidence', 0);
        $this->assertDatabaseCount('admin_actions', 0);
        $this->assertSame(['existing-private-file.pdf' => 'Keep this existing file.'], $this->fileSnapshot());
    }

    public function test_cleanup_removes_new_evidence_after_an_outer_transaction_rolls_back(): void
    {
        User::factory()->admin()->create();
        $evaluator = $this->directSeededEvaluator();
        $before = $evaluator->refresh()->getAttributes();
        Storage::disk('local')->put('existing-private-file.pdf', 'Keep this existing file.');
        $applications = new AdditionalEvaluatorApplicationsSeeder;

        try {
            DB::transaction(function () use ($applications): void {
                $applications->run();
                $this->assertDatabaseCount('evaluator_verifications', 1);
                throw new RuntimeException('Simulated outer transaction failure.');
            });
            $this->fail('The outer transaction should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated outer transaction failure.', $exception->getMessage());
            $applications->cleanupCreatedFiles();
        }

        $this->assertSame($before, $evaluator->fresh()->getAttributes());
        $this->assertDatabaseCount('evaluator_verifications', 0);
        $this->assertDatabaseCount('evaluator_evidence', 0);
        $this->assertDatabaseCount('admin_actions', 0);
        $this->assertSame(['existing-private-file.pdf' => 'Keep this existing file.'], $this->fileSnapshot());
    }

    public function test_missing_active_admin_prevents_account_creation_and_application_approval(): void
    {
        $accounts = new AdditionalEvaluatorSeeder;
        try {
            $accounts->run();
            $this->fail('An active administrator is required before creating approved accounts.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('active administrator', $exception->getMessage());
        }
        $this->assertSame([], $accounts->credentials());
        $this->assertDatabaseCount('users', 0);

        User::factory()->admin()->create(['is_suspended' => true]);
        $evaluator = $this->directSeededEvaluator();
        $before = $evaluator->refresh()->getAttributes();
        try {
            (new AdditionalEvaluatorApplicationsSeeder)->run();
            $this->fail('A suspended administrator cannot approve development applications.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('active administrator', $exception->getMessage());
        }
        $this->assertSame($before, $evaluator->fresh()->getAttributes());
        $this->assertDatabaseCount('evaluator_verifications', 0);
        $this->assertDatabaseCount('admin_actions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_application_seeding_is_refused_in_production_before_any_rows_or_files_are_created(): void
    {
        $previous = $this->app->environment();
        try {
            $this->app->instance('env', 'production');
            (new AdditionalEvaluatorApplicationsSeeder)->run();
            $this->fail('Production must not receive seeded applications.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('local or testing', $exception->getMessage());
        } finally {
            $this->app->instance('env', $previous);
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('evaluator_verifications', 0);
        $this->assertDatabaseCount('admin_actions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function directSeededEvaluator(): User
    {
        return User::factory()->evaluator()->create([
            'email' => 'adrian.mendoza@analista.test',
            'name' => 'Adrian Mendoza',
            'headline' => 'Web and database project reviewer',
            'bio' => 'I volunteer to help student teams improve web applications, database design, and practical testing plans through constructive project feedback.',
            'expertise' => ['Web', 'Database'],
            'skills' => ['Laravel', 'PHP', 'MySQL'],
            'experience_years' => 0,
            'moderation_note' => 'Approved directly at the project owner\'s request.',
        ]);
    }

    private function databaseSnapshot(): array
    {
        $snapshot = [];
        foreach (['users', 'evaluator_verifications', 'evaluator_evidence', 'admin_actions', 'activities'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    private function fileSnapshot(): array
    {
        $snapshot = [];
        foreach (Storage::disk('local')->allFiles() as $path) {
            $snapshot[$path] = Storage::disk('local')->get($path);
        }
        ksort($snapshot);

        return $snapshot;
    }
}
