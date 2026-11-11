<?php

namespace Tests\Feature;

use App\Models\DocumentVersion;
use App\Models\EvaluatorEvidence;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\SensitiveFileAccess;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\Support\BuildsEvaluatorVerification;
use Tests\TestCase;

class SensitiveFileAccessHistoryTest extends TestCase
{
    use BuildsEvaluatorVerification, RefreshDatabase;

    private string $ownedViews;

    private string $ownedDisk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $token = 'sensitive-file-history-'.bin2hex(random_bytes(8));
        $this->ownedViews = storage_path('framework/testing/'.$token);
        (new Filesystem)->ensureDirectoryExists($this->ownedViews);
        config(['view.compiled' => $this->ownedViews, 'app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
        $this->app->forgetInstance('blade.compiler');
        $this->app['view.engine.resolver']->forget('blade');
        $this->ownedDisk = storage_path('framework/testing/disks/'.$token);
        Storage::set('local', Storage::fake($token));
    }

    protected function tearDown(): void
    {
        $paths = array_filter([$this->ownedViews ?? null, $this->ownedDisk ?? null]);
        $testingRoot = realpath(storage_path('framework/testing'));
        try {
            parent::tearDown();
        } finally {
            foreach ($paths as $path) {
                $resolved = realpath($path);
                if ($testingRoot !== false && $resolved !== false
                    && str_starts_with(strtolower($resolved), strtolower(rtrim($testingRoot, '\\/').DIRECTORY_SEPARATOR))) {
                    (new Filesystem)->deleteDirectory($resolved);
                }
            }
        }
    }

    public function test_authorized_thesis_downloads_record_only_minimal_actor_file_and_version_metadata(): void
    {
        [$project, $document] = $this->thesis();
        $member = User::factory()->create();
        $project->members()->attach($member);
        $admin = User::factory()->admin()->create();
        $review = $this->review($project, $document);
        $this->withHeaders(['User-Agent' => 'PRIVATE_USER_AGENT', 'X-Forwarded-For' => '192.0.2.1']);
        foreach ([$project->owner, $member, $admin, $review->evaluator] as $actor) {
            $this->actingAs($actor)->get(route('documents.download', $document))
                ->assertOk()->assertDownload('PRIVATE_THESIS_NAME.pdf')
                ->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            $entry = SensitiveFileAccess::latest('id')->firstOrFail();
            $this->assertSame($actor->id, $entry->actor_id);
            $this->assertSame($actor->role, $entry->actor_role);
            $this->assertSame('thesis_pdf', $entry->file_type);
            $this->assertSame($document->id, $entry->file_id);
            $this->assertSame($project->id, $entry->project_id);
            $this->assertSame(1, $entry->version);
            $this->assertNull($entry->verification_id);
        }
        $this->assertDatabaseCount('sensitive_file_accesses', 4);
        $this->assertEqualsCanonicalizing([
            'id', 'actor_id', 'actor_role', 'file_type', 'file_id', 'project_id', 'verification_id', 'version', 'created_at',
        ], Schema::getColumnListing('sensitive_file_accesses'));
        $serialized = SensitiveFileAccess::all()->toJson();
        foreach (['PRIVATE_THESIS_NAME', $document->path, 'PRIVATE_DOCUMENT_CONTENT', $project->title,
            $project->owner->email, $review->evaluator->email, '192.0.2.1', 'PRIVATE_USER_AGENT'] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
    }

    public function test_exact_assigned_versions_and_public_opt_in_keep_existing_permissions_and_log_only_successes(): void
    {
        [$project, $document] = $this->thesis();
        $other = $this->document($project, 2);
        $assigned = $this->review($project, $document)->evaluator;
        $browser = User::factory()->evaluator()->create();
        $this->actingAs($assigned)->get(route('documents.download', $document))->assertOk();
        $this->get(route('documents.download', $other))->assertForbidden();
        $this->actingAs($browser)->get(route('documents.download', $document))->assertForbidden();
        $project->update(['is_public' => true, 'pdf_access' => Project::PDF_ACCESS_APPROVED_EVALUATORS]);
        foreach ([$assigned, $browser] as $actor) {
            $this->actingAs($actor)->get(route('documents.download', $other))->assertOk();
        }
        $this->assertDatabaseCount('sensitive_file_accesses', 3);
        $this->assertSame(2, SensitiveFileAccess::latest('id')->firstOrFail()->version);
        $project->update(['pdf_access' => Project::PDF_ACCESS_ASSIGNED]);
        $this->actingAs($browser)->get(route('documents.download', $document))->assertForbidden();
        $this->actingAs($assigned)->get(route('documents.download', $other))->assertForbidden();
        $this->get(route('documents.download', $document))->assertOk();
        $this->assertDatabaseCount('sensitive_file_accesses', 4);
    }

    public function test_private_review_outputs_log_team_assigned_evaluator_and_admin_without_exposing_other_reviews(): void
    {
        [$project, $document] = $this->thesis();
        $review = $this->review($project, $document, ['status' => 'completed']);
        $this->prepareReviewOutput($review);
        $member = User::factory()->create();
        $project->members()->attach($member);
        foreach ([$project->owner, $member, $review->evaluator, User::factory()->admin()->create()] as $actor) {
            $this->actingAs($actor)->get(route('reviews.output', $review))->assertOk()
                ->assertDownload('PRIVATE_REVIEW_OUTPUT.pdf')->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            $entry = SensitiveFileAccess::latest('id')->firstOrFail();
            $this->assertSame($actor->id, $entry->actor_id);
            $this->assertSame('review_pdf', $entry->file_type);
            $this->assertSame($review->id, $entry->file_id);
            $this->assertSame($project->id, $entry->project_id);
            $this->assertNull($entry->version);
        }
        $foreign = $this->review($project, $document, ['status' => 'completed']);
        $this->prepareReviewOutput($foreign);
        $this->actingAs($review->evaluator)->get(route('reviews.output', $foreign))->assertForbidden();
        $project->update(['is_public' => true, 'pdf_access' => Project::PDF_ACCESS_APPROVED_EVALUATORS]);
        $this->actingAs(User::factory()->evaluator()->create())->get(route('reviews.output', $review))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('reviews.output', $review))->assertForbidden();
        $this->assertDatabaseCount('sensitive_file_accesses', 4);
        $this->assertStringNotContainsString('PRIVATE_REVIEW_OUTPUT', SensitiveFileAccess::all()->toJson());
        $this->assertStringNotContainsString($review->output_path, SensitiveFileAccess::all()->toJson());
    }

    public function test_evaluator_evidence_logs_only_applicant_and_admin_downloads_and_keeps_mime_headers(): void
    {
        [$applicant, $evidence] = $this->evidence();
        foreach ([$applicant, User::factory()->admin()->create()] as $actor) {
            $this->actingAs($actor)->get(route('account.evaluator-evidence.download', $evidence))->assertOk()
                ->assertDownload('PRIVATE_PROOF.pdf')->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            $entry = SensitiveFileAccess::latest('id')->firstOrFail();
            $this->assertSame($actor->id, $entry->actor_id);
            $this->assertSame('evaluator_evidence', $entry->file_type);
            $this->assertSame($evidence->id, $entry->file_id);
            $this->assertSame($evidence->evaluator_verification_id, $entry->verification_id);
            $this->assertNull($entry->project_id);
            $this->assertNull($entry->version);
        }
        foreach ([User::factory()->create(), User::factory()->evaluator()->create()] as $actor) {
            $this->actingAs($actor)->get(route('account.evaluator-evidence.download', $evidence))->assertForbidden();
        }
        $this->assertDatabaseCount('sensitive_file_accesses', 2);
    }

    public function test_denied_missing_head_and_ordinary_page_requests_never_create_access_entries(): void
    {
        [$project, $document] = $this->thesis();
        $review = $this->review($project, $document, ['status' => 'completed']);
        $this->prepareReviewOutput($review);
        [$applicant, $evidence] = $this->evidence();
        $urls = [route('documents.download', $document), route('reviews.output', $review), route('account.evaluator-evidence.download', $evidence)];
        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        $unrelated = User::factory()->create();
        foreach ([$unrelated, User::factory()->pendingEvaluator()->create()] as $actor) {
            foreach ($urls as $url) {
                $this->actingAs($actor)->get($url)->assertForbidden();
            }
        }
        $suspended = User::factory()->admin()->create(['is_suspended' => true]);
        foreach ($urls as $url) {
            $this->actingAs($suspended)->get($url)->assertRedirect(route('login'));
        }
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        foreach ($urls as $url) {
            $this->head($url)->assertOk();
        }
        $this->get(route('account.projects.show', $project))->assertOk();
        $this->get(route('reviews.show', $review))->assertOk();
        $this->get(route('admin.evaluators.show', $applicant))->assertOk();
        Storage::disk('local')->delete([$document->path, $review->output_path, $evidence->path]);
        foreach ($urls as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->assertDatabaseCount('sensitive_file_accesses', 0);
        $this->assertDatabaseCount('admin_actions', 0);
        $this->assertDatabaseCount('activities', 0);
    }

    public function test_audit_failure_blocks_all_three_file_responses_without_changing_original_files_or_data(): void
    {
        config(['app.debug' => false]);
        [$project, $document] = $this->thesis();
        $review = $this->review($project, $document, ['status' => 'completed']);
        $this->prepareReviewOutput($review);
        [, $evidence] = $this->evidence();
        $dispatcher = SensitiveFileAccess::getEventDispatcher();
        SensitiveFileAccess::setEventDispatcher(clone $dispatcher);
        SensitiveFileAccess::creating(static function (): void {
            throw new RuntimeException('Access history persistence unavailable.');
        });
        $this->actingAs(User::factory()->admin()->create());
        try {
            foreach ([route('documents.download', $document), route('reviews.output', $review), route('account.evaluator-evidence.download', $evidence)] as $url) {
                $response = $this->get($url)->assertStatus(500)->assertDontSee('PRIVATE_DOCUMENT_CONTENT');
                $this->assertFalse($response->headers->has('Content-Disposition'));
            }
            foreach ([$document->path, $review->output_path, $evidence->path] as $path) {
                $this->assertSame($this->pdf(), Storage::disk('local')->get($path));
            }
            $this->assertDatabaseCount('sensitive_file_accesses', 0);
            $this->assertSame('completed', $review->fresh()->status);
            $this->assertSame('approved', $project->fresh()->status);
        } finally {
            SensitiveFileAccess::setEventDispatcher($dispatcher);
        }
    }

    public function test_failure_to_prepare_a_download_response_does_not_claim_file_access(): void
    {
        [$project, $document] = $this->thesis();
        $originalDisk = Storage::disk('local');
        $brokenDisk = Mockery::mock(FilesystemAdapter::class);
        $brokenDisk->shouldReceive('exists')->once()->with($document->path)->andReturn(true);
        $brokenDisk->shouldReceive('download')->once()->andThrow(new RuntimeException('Unable to prepare file response.'));
        Storage::set('local', $brokenDisk);
        try {
            $this->actingAs($project->owner)->get(route('documents.download', $document))->assertStatus(500);
            $this->assertDatabaseCount('sensitive_file_accesses', 0);
        } finally {
            Storage::set('local', $originalDisk);
        }
        $this->assertSame($this->pdf(), Storage::disk('local')->get($document->path));
    }

    public function test_access_history_is_admin_only_and_filters_searches_and_paginates_metadata_without_mutation(): void
    {
        [$project, $document] = $this->thesis();
        $actor = $project->owner;
        $actor->update(['name' => 'Cedar Student', 'email' => 'private-cedar@example.com']);
        $admin = User::factory()->admin()->create();
        $this->actingAs($actor);
        foreach (range(1, 22) as $index) {
            $this->get(route('documents.download', $document))->assertOk();
        }
        $url = route('admin.index', ['section' => 'file-access']);
        $this->post(route('logout'));
        $this->get($url)->assertRedirect(route('login'));
        foreach ([$actor, User::factory()->evaluator()->create()] as $viewer) {
            $this->actingAs($viewer)->get($url)->assertForbidden()->assertDontSee('Cedar Student');
        }
        $this->actingAs(User::factory()->admin()->create(['is_suspended' => true]))->get($url)->assertRedirect(route('login'));
        $snapshot = SensitiveFileAccess::orderBy('id')->get()->toJson();
        $this->actingAs($admin);
        $response = $this->get(route('admin.index', ['section' => 'file-access', 'file_type' => 'thesis_pdf', 'role' => 'student', 'q' => 'Cedar']));
        $response->assertOk()->assertViewHas('fileAccesses', fn ($items) => $items->total() === 22)
            ->assertDontSee($actor->email)->assertSee('PRIVATE_THESIS_NAME.pdf')->assertSee($project->title);
        parse_str(parse_url($response->viewData('fileAccesses')->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('file-access', $query['section']);
        $this->assertSame('thesis_pdf', $query['file_type']);
        $this->assertSame('student', $query['role']);
        $this->assertSame('Cedar', $query['q']);
        $this->assertSame('2', $query['file_access_page']);
        $this->get($response->viewData('fileAccesses')->nextPageUrl())->assertOk()
            ->assertViewHas('fileAccesses', fn ($items) => $items->count() === 2);
        foreach ([$actor->email, 'PRIVATE_THESIS_NAME', 'PRIVATE_DOCUMENT_CONTENT', '%', '_', "' OR 1=1 --"] as $q) {
            $this->get(route('admin.index', ['section' => 'file-access', 'q' => $q]))->assertOk()
                ->assertViewHas('fileAccesses', fn ($items) => $items->total() === 0);
        }
        $this->get(route('admin.index', ['section' => 'file-access', 'q' => (string) $project->id]))->assertOk()
            ->assertViewHas('fileAccesses', fn ($items) => $items->total() === 22);
        $this->get(route('admin.index', ['section' => 'file-access', 'role' => 'evaluator']))->assertOk()
            ->assertViewHas('fileAccesses', fn ($items) => $items->total() === 0);
        foreach ([['q' => str_repeat('x', 201)], ['q' => ['bad']], ['role' => 'guest'], ['file_type' => 'public_file']] as $badQuery) {
            $this->getJson(route('admin.index', ['section' => 'file-access', ...$badQuery]))->assertUnprocessable();
        }
        $this->assertSame($snapshot, SensitiveFileAccess::orderBy('id')->get()->toJson());
        $this->assertSame($this->pdf(), Storage::disk('local')->get($document->path));
        $this->assertDatabaseCount('activities', 0);
        $this->assertDatabaseCount('admin_actions', 0);
    }

    public function test_history_is_immutable_and_keeps_neutral_record_ids_after_deletion_without_exposing_stale_names(): void
    {
        [$project, $document] = $this->thesis();
        $actor = $project->owner;
        $this->actingAs($actor)->get(route('documents.download', $document))->assertOk();
        $entry = SensitiveFileAccess::sole();
        $entryId = $entry->id;
        $projectId = $project->id;
        $fileId = $document->id;
        try {
            $entry->update(['file_type' => 'review_pdf']);
            $this->fail('Sensitive file history must reject updates.');
        } catch (LogicException) {
            $this->assertSame('thesis_pdf', $entry->fresh()->file_type);
        }
        try {
            $entry->delete();
            $this->fail('Sensitive file history must reject deletion.');
        } catch (LogicException) {
            $this->assertDatabaseHas('sensitive_file_accesses', ['id' => $entryId]);
        }
        $project->delete();
        $actor->delete();
        $entry = $entry->fresh();
        $this->assertNull($entry->actor_id);
        $this->assertSame('student', $entry->actor_role);
        $this->assertSame($projectId, $entry->project_id);
        $this->assertSame($fileId, $entry->file_id);
        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->get(route('admin.index', ['section' => 'file-access']))->assertOk()
            ->assertDontSee($actor->name)->assertDontSee($actor->email)->assertDontSee($project->title);
        $this->assertSame('Former account requested a thesis PDF download', $this->accessHeading($response->getContent(), $entryId));
        $response->assertSee('File no longer available')->assertSee('Unavailable');
        $this->assertNoTechnicalMetadata($response->getContent(), $entryId);
        $this->assertSame('Thesis PDF #'.$fileId, $entry->fileLabel());
        $this->assertDatabaseHas('sensitive_file_accesses', [
            'id' => $entryId, 'file_id' => $fileId, 'project_id' => $projectId, 'version' => 1,
        ]);
    }

    public function test_file_access_context_uses_current_escaped_names_and_titles_without_copying_them_into_history(): void
    {
        [$project, $document] = $this->thesis();
        $project->update(['title' => 'Cedar campus thesis']);
        $review = $this->review($project, $document, ['status' => 'completed']);
        $this->prepareReviewOutput($review);
        [, $evidence] = $this->evidence();
        $admin = User::factory()->admin()->create(['name' => 'Review Administrator']);
        $this->actingAs($admin);
        $this->get(route('documents.download', $document))->assertOk();
        $this->get(route('reviews.output', $review))->assertOk();
        $this->get(route('account.evaluator-evidence.download', $evidence))->assertOk();
        $entries = SensitiveFileAccess::all()->keyBy('file_type');
        $storedRows = SensitiveFileAccess::orderBy('id')->get()->toJson();
        $url = route('admin.index', ['section' => 'file-access']);
        $response = $this->get($url)->assertOk();
        foreach (['thesis_pdf', 'review_pdf'] as $type) {
            $action = $type === 'thesis_pdf' ? 'thesis' : 'review';
            $this->assertSame('Review Administrator requested a '.$action.' PDF download', $this->accessHeading($response->getContent(), $entries[$type]->id));
            $this->assertSame($project->title, $this->accessDetail($response->getContent(), $entries[$type]->id, 'Project'));
            $this->assertNoTechnicalMetadata($response->getContent(), $entries[$type]->id);
        }
        $this->assertSame('Review Administrator requested a verification file download', $this->accessHeading($response->getContent(), $entries['evaluator_evidence']->id));
        $this->assertNoTechnicalMetadata($response->getContent(), $entries['evaluator_evidence']->id);
        foreach ($entries as $entry) {
            $this->assertDatabaseHas('sensitive_file_accesses', [
                'id' => $entry->id, 'file_type' => $entry->file_type, 'file_id' => $entry->file_id,
                'project_id' => $entry->project_id, 'verification_id' => $entry->verification_id,
                'version' => $entry->version,
            ]);
        }

        $renamedTitle = '<script>alert("title")</script> & "Cedar revised thesis"';
        $project->update(['title' => $renamedTitle]);
        $renamed = $this->get($url)->assertOk()->assertSee(e($renamedTitle), false)->assertDontSee($renamedTitle, false);
        foreach (['thesis_pdf', 'review_pdf'] as $type) {
            $this->assertSame($renamedTitle, $this->accessDetail($renamed->getContent(), $entries[$type]->id, 'Project'));
        }
        $this->assertSame('Review Administrator requested a verification file download', $this->accessHeading($renamed->getContent(), $entries['evaluator_evidence']->id));
        $project->delete();
        $deleted = $this->get($url)->assertOk();
        foreach (['thesis_pdf' => 'Thesis PDF', 'review_pdf' => 'Review PDF'] as $type => $label) {
            $this->assertStringContainsString('requested a ', $this->accessHeading($deleted->getContent(), $entries[$type]->id));
            $this->assertSame('', $this->accessDetail($deleted->getContent(), $entries[$type]->id, 'Project'));
            $this->assertSame('File no longer available', $this->accessDetail($deleted->getContent(), $entries[$type]->id, 'File'));
            $this->assertNoTechnicalMetadata($deleted->getContent(), $entries[$type]->id);
        }
        $this->assertSame($storedRows, SensitiveFileAccess::orderBy('id')->get()->toJson());
        $this->assertStringNotContainsString('Cedar campus thesis', $storedRows);
        $this->assertStringNotContainsString('Cedar revised thesis', SensitiveFileAccess::all()->toJson());
        $this->assertDatabaseCount('admin_actions', 0);
    }

    public function test_access_context_distinguishes_the_viewer_from_the_file_submitter_for_each_file_type(): void
    {
        [$project, $document] = $this->thesis();
        $uploader = User::factory()->create(['name' => 'Student Uploader']);
        $document->update(['user_id' => $uploader->id, 'original_name' => 'Research manuscript.pdf']);
        $reviewer = User::factory()->evaluator()->create(['name' => 'Assigned Evaluator']);
        $review = $this->review($project, $document, ['status' => 'completed', 'evaluator_id' => $reviewer->id]);
        $this->prepareReviewOutput($review);
        [$applicant, $evidence] = $this->evidence();
        $applicant->update(['name' => 'Evidence Applicant']);
        $evidence->update(['original_name' => '<img src=x onerror=alert(1)> & background.pdf']);
        $admin = User::factory()->admin()->create(['name' => 'File Reviewer']);
        $this->actingAs($admin);
        $this->get(route('documents.download', $document))->assertOk();
        $this->get(route('reviews.output', $review))->assertOk();
        $this->get(route('account.evaluator-evidence.download', $evidence))->assertOk();
        $rows = SensitiveFileAccess::all()->keyBy('file_type');
        $before = SensitiveFileAccess::orderBy('id')->get()->toJson();
        $url = route('admin.index', ['section' => 'file-access']);
        $response = $this->get($url)->assertOk()->assertSee('Evaluator verification')
            ->assertSee(e($evidence->original_name), false)->assertDontSee($evidence->original_name, false)
            ->assertDontSee($document->path)->assertDontSee($review->output_path)->assertDontSee($evidence->path)
            ->assertDontSee($admin->email)->assertDontSee($applicant->email);
        foreach (['thesis_pdf' => [$document->original_name, 'Student Uploader'], 'review_pdf' => [$review->output_original_name, 'Assigned Evaluator'], 'evaluator_evidence' => [$evidence->original_name, 'Evidence Applicant']] as $type => [$filename, $submitter]) {
            $entry = $rows[$type];
            $this->assertStringStartsWith('File Reviewer requested ', $this->accessHeading($response->getContent(), $entry->id));
            $this->assertSame($filename, $this->accessDetail($response->getContent(), $entry->id, 'File'));
            $this->assertSame($submitter, $this->accessDetail($response->getContent(), $entry->id, 'Submitted by'));
            $this->assertNoTechnicalMetadata($response->getContent(), $entry->id);
        }
        $applicant->update(['name' => 'Updated Applicant']);
        $evidence->update(['original_name' => 'Updated professional background.pdf']);
        $renamed = $this->get($url)->assertOk();
        $this->assertSame('Updated Applicant', $this->accessDetail($renamed->getContent(), $rows['evaluator_evidence']->id, 'Submitted by'));
        $this->assertSame('Updated professional background.pdf', $this->accessDetail($renamed->getContent(), $rows['evaluator_evidence']->id, 'File'));
        $evidence->delete();
        $deleted = $this->get($url)->assertOk()->assertDontSee('Updated professional background.pdf')->assertDontSee('Updated Applicant');
        $this->assertSame('File no longer available', $this->accessDetail($deleted->getContent(), $rows['evaluator_evidence']->id, 'File'));
        $this->assertSame($before, SensitiveFileAccess::orderBy('id')->get()->toJson());
        $this->assertStringNotContainsString('Student Uploader', $before);
        $this->assertDatabaseCount('admin_actions', 0);
    }

    public function test_access_context_does_not_associate_files_from_a_different_recorded_parent(): void
    {
        [$project, $document] = $this->thesis();
        [$otherProject, $otherDocument] = $this->thesis();
        [$applicant, $evidence] = $this->evidence();
        [$otherApplicant, $otherEvidence] = $this->evidence();
        $admin = User::factory()->admin()->create();
        $thesisEntry = SensitiveFileAccess::create(['actor_id' => $admin->id, 'actor_role' => 'admin', 'file_type' => 'thesis_pdf', 'file_id' => $otherDocument->id, 'project_id' => $project->id]);
        $evidenceEntry = SensitiveFileAccess::create(['actor_id' => $admin->id, 'actor_role' => 'admin', 'file_type' => 'evaluator_evidence', 'file_id' => $otherEvidence->id, 'verification_id' => $evidence->evaluator_verification_id]);
        $response = $this->actingAs($admin)->get(route('admin.index', ['section' => 'file-access']))->assertOk();
        foreach ([$thesisEntry, $evidenceEntry] as $entry) {
            $this->assertSame('File no longer available', $this->accessDetail($response->getContent(), $entry->id, 'File'));
            $this->assertSame('Unavailable', $this->accessDetail($response->getContent(), $entry->id, 'Submitted by'));
        }
        $response->assertDontSee($otherProject->owner->name)->assertDontSee($otherApplicant->name);
    }

    private function accessDetail(string $html, int $entryId, string $label): string
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $values = (new \DOMXPath($document))->query('//article[@data-access-id="'.$entryId.'"]//dl/div[dt="'.$label.'"]/dd');

        return $values->length ? trim($values->item(0)->textContent) : '';
    }

    private function accessHeading(string $html, int $entryId): string
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $headings = (new \DOMXPath($document))->query('//article[@data-access-id="'.$entryId.'"]//h3');
        $this->assertCount(1, $headings);

        return trim($headings->item(0)->textContent);
    }

    private function assertNoTechnicalMetadata(string $html, int $entryId): void
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $entries = (new \DOMXPath($document))->query('//article[@data-access-id="'.$entryId.'"]');
        $this->assertCount(1, $entries);
        $entry = $entries->item(0);
        $this->assertDoesNotMatchRegularExpression('/(?:Thesis PDF|Review PDF|Evaluator evidence|Project|Application|Entry)\s+#\d+|PDF version\s+\d+/u', $entry->textContent);
        $this->assertStringContainsString('Download requested', $entry->textContent);
        $this->assertGreaterThanOrEqual(2, (new \DOMXPath($document))->query('.//dl/div', $entry)->length);
        $this->assertCount(1, (new \DOMXPath($document))->query('.//time[@datetime]', $entry));
    }

    private function thesis(): array
    {
        $project = Project::factory()->approved()->create(['title' => 'PRIVATE_THESIS_TITLE']);

        return [$project, $this->document($project, 1)];
    }

    private function document(Project $project, int $version): DocumentVersion
    {
        $document = DocumentVersion::factory()->create([
            'project_id' => $project->id, 'user_id' => $project->user_id, 'version' => $version,
            'original_name' => 'PRIVATE_THESIS_NAME.pdf',
        ]);
        Storage::disk('local')->put($document->path, $this->pdf());

        return $document;
    }

    private function review(Project $project, DocumentVersion $document, array $overrides = []): ReviewRequest
    {
        return ReviewRequest::factory()->accepted()->create([
            'project_id' => $project->id, 'document_version_id' => $document->id, ...$overrides,
        ]);
    }

    private function prepareReviewOutput(ReviewRequest $review): void
    {
        $review->update([
            'output_path' => 'review-outputs/'.$review->id.'/'.str_repeat('A', 40).'.pdf',
            'output_original_name' => 'PRIVATE_REVIEW_OUTPUT.pdf', 'output_size' => strlen($this->pdf()),
        ]);
        Storage::disk('local')->put($review->output_path, $this->pdf());
    }

    private function evidence(): array
    {
        $applicant = User::factory()->pendingEvaluator()->create();
        $verification = $this->createVerification($applicant, ['evidence_type' => 'document']);
        $path = 'private-proof/'.bin2hex(random_bytes(20)).'.pdf';
        Storage::disk('local')->put($path, $this->pdf());
        $evidence = EvaluatorEvidence::create([
            'evaluator_verification_id' => $verification->id, 'path' => $path, 'original_name' => 'PRIVATE_PROOF.pdf',
            'mime_type' => 'application/pdf', 'size' => strlen($this->pdf()),
        ]);

        return [$applicant, $evidence];
    }

    private function pdf(): string
    {
        return "%PDF-1.4\nPRIVATE_DOCUMENT_CONTENT\n%%EOF";
    }
}
