<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\DocumentVersion;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReviewRequest;
use App\Models\Tutorial;
use App\Models\User;
use App\Support\AdminAudit;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\Support\BuildsEvaluatorVerification;
use Tests\TestCase;

class AdminPrivacyFeaturesTest extends TestCase
{
    use BuildsEvaluatorVerification, RefreshDatabase;

    private string $ownedViews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->ownedViews = storage_path('framework/testing/admin-privacy-'.bin2hex(random_bytes(8)));
        (new Filesystem)->ensureDirectoryExists($this->ownedViews);
        config(['view.compiled' => $this->ownedViews, 'app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
        $this->app->forgetInstance('blade.compiler');
        $this->app['view.engine.resolver']->forget('blade');
    }

    protected function tearDown(): void
    {
        $ownedViews = $this->ownedViews ?? null;
        try {
            parent::tearDown();
        } finally {
            if ($ownedViews && str_starts_with($ownedViews, storage_path('framework/testing/admin-privacy-'))) {
                (new Filesystem)->deleteDirectory($ownedViews);
            }
        }
    }

    public function test_search_and_history_remain_restricted_to_active_administrators(): void
    {
        $admin = User::factory()->admin()->create();
        $subject = User::factory()->create(['name' => 'Restricted history subject']);
        AdminAudit::record($admin, 'user.suspended', 'user', $subject->id,
            ['is_suspended' => false], ['is_suspended' => true], 'Confidential moderation explanation.');

        $parameters = ['section' => 'history', 'q' => $subject->id];
        $this->get(route('admin.index', $parameters))->assertRedirect(route('login'))
            ->assertDontSee('Confidential moderation explanation.');
        foreach ([User::factory()->create(), User::factory()->evaluator()->create()] as $viewer) {
            foreach (['history', 'evaluators', 'projects', 'reports', 'users', 'tutorials', 'invitations'] as $section) {
                $this->actingAs($viewer)->get(route('admin.index', ['section' => $section, 'q' => 'private']))
                    ->assertForbidden()->assertDontSee('Confidential moderation explanation.');
            }
        }
        $suspended = User::factory()->admin()->create(['is_suspended' => true]);
        $this->actingAs($suspended)->get(route('admin.index', $parameters))
            ->assertRedirect(route('login'))->assertDontSee('Confidential moderation explanation.');
        $this->actingAs($admin)->get(route('admin.index', ['section' => 'history']))->assertOk()
            ->assertSee('Confidential moderation explanation.');
    }

    public function test_filters_are_bounded_and_reject_section_inappropriate_values(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        foreach ([
            ['section' => 'users', 'q' => str_repeat('a', 201)],
            ['section' => 'users', 'q' => ['unexpected']],
            ['section' => 'users', 'role' => 'owner'],
            ['section' => 'projects', 'status' => 'suspended'],
            ['section' => 'evaluators', 'status' => 'draft'],
            ['section' => 'reports', 'status' => 'approved'],
            ['section' => 'tutorials', 'status' => 'resolved'],
            ['section' => 'invitations', 'status' => 'draft'],
            ['section' => 'history', 'action' => 'user.deleted'],
            ['section' => 'history', 'action' => ['user.suspended']],
        ] as $query) {
            $this->getJson(route('admin.index', $query))->assertUnprocessable();
        }
        $this->get(route('admin.index', ['section' => 'users', 'q' => "' OR 1=1 --"]))
            ->assertOk()->assertViewHas('users', fn ($users) => $users->total() === 0);
        $this->get(route('admin.index', ['section' => 'users', 'q' => '%']))
            ->assertOk()->assertViewHas('users', fn ($users) => $users->total() === 0);
    }

    public function test_projects_hide_drafts_by_default_and_keep_search_filters_across_pagination(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $draft = $this->project($owner, ['title' => 'Orchard draft', 'status' => 'draft']);
        $hidden = $this->project($owner, ['title' => 'Orchard hidden', 'status' => 'hidden']);
        $approved = $this->project($owner, ['title' => 'Orchard approved', 'status' => 'approved']);
        $this->actingAs($admin)->get(route('admin.index', ['section' => 'projects']))->assertOk()
            ->assertViewHas('projects', fn ($projects) => $projects->total() === 2
                && ! $projects->contains('id', $draft->id));
        $this->get(route('admin.index', ['section' => 'projects', 'status' => '']))->assertOk()
            ->assertViewHas('projects', fn ($projects) => $projects->total() === 2
                && ! $projects->contains('id', $draft->id));
        $this->get(route('admin.index', ['section' => 'projects', 'status' => 'draft', 'q' => 'Orchard']))
            ->assertOk()->assertViewHas('projects', fn ($projects) => $projects->modelKeys() === [$draft->id]);
        $this->get(route('admin.index', ['section' => 'projects', 'status' => 'all', 'q' => 'Orchard']))
            ->assertOk()->assertViewHas('projects', fn ($projects) => $projects->total() === 3);
        $this->get(route('admin.index', ['section' => 'projects', 'status' => 'hidden', 'q' => 'Orchard']))
            ->assertOk()->assertViewHas('projects', fn ($projects) => $projects->modelKeys() === [$hidden->id]);

        foreach (range(1, 22) as $number) {
            $this->project($owner, ['title' => 'Pagination Orchard '.$number]);
        }
        $response = $this->get(route('admin.index', ['section' => 'projects', 'status' => 'approved', 'q' => 'Orchard']));
        $response->assertOk()->assertViewHas('projects', fn ($projects) => $projects->total() === 23);
        parse_str(parse_url($response->viewData('projects')->nextPageUrl(), PHP_URL_QUERY), $nextQuery);
        $this->assertSame('projects', $nextQuery['section']);
        $this->assertSame('approved', $nextQuery['status']);
        $this->assertSame('Orchard', $nextQuery['q']);
        $this->assertSame('2', $nextQuery['projects_page']);
        $this->get($response->viewData('projects')->nextPageUrl())->assertOk()
            ->assertViewHas('projects', fn ($projects) => $projects->count() === 3);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('approved', $approved->fresh()->status);
    }

    public function test_each_list_filters_metadata_and_never_searches_private_material_contents(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->create(['name' => 'Cedar Student', 'email' => 'cedar-student@example.com']);
        $evaluator = User::factory()->pendingEvaluator()->create(['name' => 'Cedar Evaluator']);
        $this->createVerification($evaluator, [
            'evidence_explanation' => 'PRIVATE_EVIDENCE_SEARCH '.str_repeat('Work evidence. ', 20),
            'sample_review' => 'PRIVATE_REVIEW_SEARCH '.str_repeat('Constructive review. ', 20),
        ]);
        User::factory()->evaluator()->create(['name' => 'Cedar Approved']);
        User::factory()->create(['name' => 'Cedar Suspended', 'is_suspended' => true]);
        $project = $this->project($student, ['title' => 'Cedar thesis', 'summary' => 'PRIVATE_THESIS_SEARCH']);
        $this->document($project);
        $report = $this->report($student, $project, ['details' => 'PRIVATE_REPORT_SEARCH']);
        $this->report($student, $project, ['status' => 'resolved']);
        $tutorial = $this->tutorial($admin, ['title' => 'Cedar published guide', 'body' => 'PRIVATE_BODY_SEARCH']);
        $this->tutorial($admin, ['title' => 'Cedar draft guide', 'slug' => 'cedar-draft', 'is_published' => false]);
        $invitation = $this->invitation($admin, ['email' => 'cedar-pending@example.com']);
        $this->invitation($admin, ['email' => 'cedar-expired@example.com', 'expires_at' => now()->subSecond()]);
        $this->actingAs($admin);

        foreach ([
            ['evaluators', 'pending', 'Cedar', $evaluator->id],
            ['users', 'active', 'Cedar Student', $student->id],
            ['reports', 'pending', 'Cedar', $report->id],
            ['tutorials', 'published', 'Cedar', $tutorial->id],
            ['invitations', 'pending', 'Cedar', $invitation->id],
        ] as [$section, $status, $query, $id]) {
            $this->get(route('admin.index', ['section' => $section, 'status' => $status, 'q' => $query]))
                ->assertOk()->assertViewHas($section, fn ($items) => $items->modelKeys() === [$id]);
        }
        $this->get(route('admin.index', ['section' => 'users', 'role' => 'student', 'status' => 'active', 'q' => 'Cedar']))
            ->assertOk()->assertViewHas('users', fn ($users) => $users->modelKeys() === [$student->id]);
        foreach ([
            ['evaluators', 'PRIVATE_EVIDENCE_SEARCH'],
            ['evaluators', 'PRIVATE_REVIEW_SEARCH'],
            ['projects', 'PRIVATE_THESIS_SEARCH'],
            ['projects', 'PRIVATE_THESIS_NAME'],
            ['reports', 'PRIVATE_REPORT_SEARCH'],
            ['tutorials', 'PRIVATE_BODY_SEARCH'],
            ['invitations', $invitation->token_hash],
        ] as [$section, $query]) {
            $this->get(route('admin.index', ['section' => $section, 'q' => $query]))
                ->assertOk()->assertViewHas($section, fn ($items) => $items->total() === 0);
        }
        $this->assertSame('pending', $evaluator->fresh()->evaluator_status);
        $this->assertSame('approved', $project->fresh()->status);
        $this->assertDatabaseCount('admin_actions', 0);
    }

    public function test_overview_shows_only_aggregates_and_seven_day_trends_with_exact_boundaries(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(12, 0));
        $old = now()->subDays(15);
        $admin = User::factory()->admin()->create(['created_at' => $old]);
        $owner = User::factory()->create(['name' => 'PRIVATE_OWNER_NAME', 'email' => 'private-owner@example.com', 'created_at' => $old]);
        $evaluator = User::factory()->evaluator()->create(['name' => 'PRIVATE_EVALUATOR_NAME', 'created_at' => $old]);
        $source = $this->project($owner, ['title' => 'PRIVATE_PROJECT_TITLE', 'summary' => 'PRIVATE_PROJECT_CONTENT', 'created_at' => $old]);
        $document = $this->document($source);
        foreach ([now()->subDays(14)->subSecond(), now()->subDays(14), now()->subDays(7), now(), now()->addSecond()] as $date) {
            User::factory()->create(['created_at' => $date]);
            $this->project($owner, ['status' => 'draft', 'created_at' => $date]);
            $this->createVerification($evaluator, ['created_at' => $date]);
            ReviewRequest::create([
                'project_id' => $source->id, 'evaluator_id' => $evaluator->id, 'document_version_id' => $document->id,
                'requested_by' => $owner->id, 'status' => 'completed', 'message' => 'PRIVATE_REVIEW_MESSAGE',
                'completed_at' => $date, 'created_at' => $old,
            ]);
        }
        $this->report($owner, $source, ['details' => 'PRIVATE_REPORT_DETAILS']);
        $response = $this->actingAs($admin)->get(route('admin.index'));
        $response->assertOk();
        foreach ($response->viewData('trends') as $trend) {
            $this->assertSame(2, $trend['current'], $trend['label'].' current window');
            $this->assertSame(1, $trend['previous'], $trend['label'].' previous window');
        }
        $this->assertCount(4, $response->viewData('trends'));
        $this->assertSame(6, $response->viewData('stats')['projects']);
        $this->assertSame(5, $response->viewData('stats')['drafts']);
        foreach (['PRIVATE_OWNER_NAME', 'private-owner@example.com', 'PRIVATE_EVALUATOR_NAME', 'PRIVATE_PROJECT_TITLE',
            'PRIVATE_PROJECT_CONTENT', 'PRIVATE_REVIEW_MESSAGE', 'PRIVATE_REPORT_DETAILS'] as $private) {
            $response->assertDontSee($private);
        }
        $this->assertDatabaseCount('admin_actions', 0);
    }

    public function test_successful_evaluator_project_and_account_decisions_record_minimal_history_once(): void
    {
        $admin = User::factory()->admin()->create();
        $evaluator = User::factory()->pendingEvaluator()->create();
        $verification = $this->createVerification($evaluator, ['sample_review' => 'PRIVATE_SAMPLE_REVIEW '.str_repeat('Constructive feedback. ', 20)]);
        $this->actingAs($admin)->post(route('admin.evaluators.moderate', $evaluator), $this->approvalData($verification))
            ->assertRedirect()->assertSessionHasNoErrors();
        $entry = AdminAction::where('action', 'evaluator.approved')->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame('evaluator', $entry->target_type);
        $this->assertSame($evaluator->id, $entry->target_id);
        $this->assertSame('pending', $entry->before_values['evaluator_status']);
        $this->assertSame('approved', $entry->after_values['evaluator_status']);
        $this->assertSame($verification->id, $entry->metadata['verification_id']);
        $this->assertStringNotContainsString('PRIVATE_SAMPLE_REVIEW', $entry->toJson());
        $this->assertStringNotContainsString($verification->evidence_url, $entry->toJson());

        $project = $this->project(User::factory()->create(), ['summary' => 'PRIVATE_THESIS_CONTENT']);
        $this->document($project);
        $reason = 'Unrelated public listing content.';
        $this->post(route('admin.projects.moderate', $project), ['decision' => 'hide', 'reason' => $reason])
            ->assertRedirect()->assertSessionHasNoErrors();
        $entry = AdminAction::where('action', 'project.hidden')->sole();
        $this->assertSame(['status' => 'approved'], $entry->before_values);
        $this->assertSame(['status' => 'hidden'], $entry->after_values);
        $this->assertSame($reason, $entry->reason);
        $this->assertStringNotContainsString('PRIVATE_THESIS_CONTENT', $entry->toJson());
        $count = AdminAction::count();
        $this->post(route('admin.projects.moderate', $project), ['decision' => 'hide', 'reason' => $reason])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('admin_actions', $count);
        $this->post(route('admin.projects.moderate', $project), ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('admin_actions', ['actor_id' => $admin->id, 'action' => 'project.approved', 'target_id' => $project->id]);

        $member = User::factory()->create(['password' => 'NeverCopyThisPassword!', 'remember_token' => 'PRIVATE_REMEMBER_TOKEN']);
        $this->post(route('admin.users.toggle', $member), ['action' => 'suspend'])->assertSessionHasNoErrors();
        $count = AdminAction::count();
        $this->post(route('admin.users.toggle', $member), ['action' => 'suspend'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('admin_actions', $count);
        $this->post(route('admin.users.toggle', $member), ['action' => 'reinstate'])->assertSessionHasNoErrors();
        foreach (['user.suspended', 'user.reinstated'] as $action) {
            $entry = AdminAction::where('action', $action)->sole();
            $this->assertSame($member->id, $entry->target_id);
            $this->assertStringNotContainsString($member->password, $entry->toJson());
            $this->assertStringNotContainsString('PRIVATE_REMEMBER_TOKEN', $entry->toJson());
        }
    }

    public function test_report_tutorial_and_invitation_actions_are_audited_without_copying_private_content(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $project = $this->project($owner);
        $report = $this->report($owner, $project, ['details' => 'PRIVATE_REPORT_DETAILS']);
        $this->actingAs($admin)->post(route('admin.reports.moderate', $report), [
            'status' => 'resolved', 'action' => 'hide_content', 'admin_note' => 'Confirmed unrelated content.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $entry = AdminAction::where('action', 'report.resolved')->sole();
        $this->assertSame($report->id, $entry->target_id);
        $this->assertSame('pending', $entry->before_values['status']);
        $this->assertSame('resolved', $entry->after_values['status']);
        $this->assertSame('approved', $entry->before_values['project_status']);
        $this->assertSame('hidden', $entry->after_values['project_status']);
        $this->assertSame($project->id, $entry->metadata['affected_id']);
        $this->assertSame('project', $entry->metadata['affected_type']);
        $this->assertStringNotContainsString('PRIVATE_REPORT_DETAILS', $entry->toJson());
        $this->assertStringNotContainsString($owner->email, $entry->toJson());

        $payload = $this->tutorialPayload();
        $this->post(route('admin.tutorials.store'), $payload)->assertSessionHasNoErrors();
        $tutorial = Tutorial::sole();
        $this->put(route('admin.tutorials.update', $tutorial), [...$payload, 'is_published' => false])
            ->assertSessionHasNoErrors();
        $count = AdminAction::count();
        $this->put(route('admin.tutorials.update', $tutorial), [...$payload, 'is_published' => false])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('admin_actions', $count);
        $this->delete(route('admin.tutorials.destroy', $tutorial))->assertRedirect();
        foreach (['tutorial.created', 'tutorial.updated', 'tutorial.deleted'] as $action) {
            $entry = AdminAction::where('action', $action)->sole();
            $this->assertSame($tutorial->id, $entry->target_id);
            $this->assertStringNotContainsString('PRIVATE_TUTORIAL_BODY', $entry->toJson());
            $this->assertStringNotContainsString($payload['youtube_url'], $entry->toJson());
        }

        $email = 'private-invitee@example.com';
        $this->post(route('admin.invitations.store'), ['email' => $email])->assertSessionHasNoErrors();
        parse_str(parse_url(session('invitation_url'), PHP_URL_QUERY), $query);
        $token = $query['invite'];
        $oldInvitation = Invitation::sole();
        $this->post(route('admin.invitations.store'), ['email' => $email])->assertSessionHasNoErrors();
        $this->assertSame(2, AdminAction::where('action', 'invitation.created')->count());
        $this->assertDatabaseHas('admin_actions', ['action' => 'invitation.invalidated', 'target_id' => $oldInvitation->id]);
        foreach (AdminAction::where('target_type', 'invitation')->get() as $entry) {
            $this->assertStringNotContainsString($email, $entry->toJson());
            $this->assertStringNotContainsString($token, $entry->toJson());
            $this->assertStringNotContainsString(hash('sha256', $token), $entry->toJson());
        }
    }

    public function test_failed_or_forbidden_mutations_do_not_create_history(): void
    {
        $admin = User::factory()->admin()->create();
        $evaluator = User::factory()->pendingEvaluator()->create();
        $project = $this->project(User::factory()->create(), ['status' => 'hidden']);
        $this->actingAs($admin)->post(route('admin.projects.moderate', $project), ['decision' => 'approve'])
            ->assertSessionHasErrors('decision');
        $this->post(route('admin.evaluators.moderate', $evaluator), ['decision' => 'approve'])
            ->assertSessionHasErrors('verification_id');
        $this->post(route('admin.users.toggle', $admin), ['action' => 'suspend'])->assertForbidden();
        $this->post(route('admin.invitations.store'), ['email' => $admin->email])->assertSessionHasErrors('email');
        $this->post(route('admin.tutorials.store'), ['title' => 'Incomplete tutorial'])->assertSessionHasErrors();
        $this->actingAs($project->owner)->post(route('admin.projects.moderate', $project), [
            'decision' => 'hide', 'reason' => 'An unauthorized attempt.',
        ])->assertForbidden();
        $this->assertDatabaseCount('admin_actions', 0);
        $this->assertSame('hidden', $project->fresh()->status);
    }

    public function test_audit_failure_rolls_back_moderation_and_related_notifications(): void
    {
        $admin = User::factory()->admin()->create();
        $project = $this->project(User::factory()->create());
        $evaluator = User::factory()->pendingEvaluator()->create();
        $verification = $this->createVerification($evaluator);
        $report = $this->report($project->owner, $project);
        $invitation = $this->invitation($admin, ['email' => 'rollback@example.com']);
        $expiresAt = $invitation->expires_at->timestamp;
        $dispatcher = AdminAction::getEventDispatcher();
        AdminAction::setEventDispatcher(clone $dispatcher);
        AdminAction::creating(static function (): void {
            throw new RuntimeException('Simulated audit persistence failure.');
        });
        try {
            $this->actingAs($admin)->post(route('admin.projects.moderate', $project), [
                'decision' => 'hide', 'reason' => 'A reviewed moderation reason.',
            ])->assertStatus(500);
            $this->assertSame('approved', $project->fresh()->status);
            $this->post(route('admin.evaluators.moderate', $evaluator), $this->approvalData($verification))->assertStatus(500);
            $this->assertSame('pending', $evaluator->fresh()->evaluator_status);
            $this->assertSame('pending', $verification->fresh()->status);
            $this->post(route('admin.reports.moderate', $report), [
                'status' => 'resolved', 'action' => 'suspend_account', 'admin_note' => 'A reviewed report reason.',
            ])->assertStatus(500);
            $this->assertSame('pending', $report->fresh()->status);
            $this->assertFalse($project->owner->fresh()->is_suspended);
            $this->post(route('admin.users.toggle', $project->owner), ['action' => 'suspend'])->assertStatus(500);
            $this->assertFalse($project->owner->fresh()->is_suspended);
            $this->post(route('admin.tutorials.store'), $this->tutorialPayload())->assertStatus(500);
            $this->assertDatabaseCount('tutorials', 0);
            $this->post(route('admin.invitations.store'), ['email' => 'rollback@example.com'])->assertStatus(500);
            $this->assertDatabaseCount('invitations', 1);
            $this->assertSame($expiresAt, $invitation->fresh()->expires_at->timestamp);
            $this->assertDatabaseCount('activities', 0);
            $this->assertDatabaseCount('admin_actions', 0);
        } finally {
            AdminAction::setEventDispatcher($dispatcher);
        }
    }

    public function test_history_uses_allowlisted_metadata_redacts_sensitive_reasons_and_cannot_be_edited(): void
    {
        $admin = User::factory()->admin()->create();
        $subject = User::factory()->create();
        $token = str_repeat('a1', 32);
        $entry = AdminAudit::record($admin, 'user.suspended', 'user', $subject->id, [
            'is_suspended' => false, 'email' => $subject->email, 'password' => 'PRIVATE_PASSWORD',
        ], [
            'is_suspended' => true, 'remember_token' => 'PRIVATE_REMEMBER_TOKEN', 'body' => 'PRIVATE_CONTENT',
        ], 'Review contact private@example.com at https://example.com/private?token='.$token.' password=SECRET_PASSWORD', [
            'affected_id' => $subject->id, 'affected_type' => 'user', 'token_hash' => $token,
            'ip_address' => '192.0.2.1', 'user_agent' => 'PRIVATE_USER_AGENT',
        ]);
        $this->assertSame(['is_suspended' => false], $entry->before_values);
        $this->assertSame(['is_suspended' => true], $entry->after_values);
        $this->assertSame(['affected_id' => $subject->id, 'affected_type' => 'user'], $entry->metadata);
        foreach ([$subject->email, 'private@example.com', 'https://example.com/private', $token,
            'SECRET_PASSWORD', 'PRIVATE_PASSWORD', 'PRIVATE_REMEMBER_TOKEN', 'PRIVATE_CONTENT', 'PRIVATE_USER_AGENT'] as $secret) {
            $this->assertStringNotContainsString($secret, $entry->toJson());
        }

        try {
            $entry->update(['reason' => 'Rewrite the history.']);
            $this->fail('Admin history must reject updates.');
        } catch (LogicException) {
            $this->assertNotSame('Rewrite the history.', $entry->fresh()->reason);
        }
        try {
            $entry->delete();
            $this->fail('Admin history must reject deletion.');
        } catch (LogicException) {
            $this->assertDatabaseHas('admin_actions', ['id' => $entry->id]);
        }
    }

    public function test_history_filters_by_actor_action_and_record_metadata_without_searching_reasons(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Cedar Administrator']);
        $other = User::factory()->admin()->create(['name' => 'Oak Administrator']);
        $subject = User::factory()->create();
        $expected = AdminAudit::record($admin, 'user.suspended', 'user', $subject->id,
            ['is_suspended' => false], ['is_suspended' => true], 'PRIVATE_HISTORY_REASON');
        AdminAudit::record($other, 'user.reinstated', 'user', $subject->id,
            ['is_suspended' => true], ['is_suspended' => false]);
        $this->actingAs($admin)->get(route('admin.index', ['section' => 'history', 'q' => 'Cedar', 'action' => 'user.suspended']))
            ->assertOk()->assertViewHas('history', fn ($history) => $history->modelKeys() === [$expected->id]);
        $this->get(route('admin.index', ['section' => 'history', 'q' => 'PRIVATE_HISTORY_REASON']))
            ->assertOk()->assertViewHas('history', fn ($history) => $history->total() === 0);
        $this->get(route('admin.index', ['section' => 'history', 'q' => (string) $subject->id]))
            ->assertOk()->assertViewHas('history', fn ($history) => $history->total() === 2);
        $this->get(route('admin.index', ['section' => 'history', 'q' => 'Oak', 'action' => 'user.suspended']))
            ->assertOk()->assertViewHas('history', fn ($history) => $history->total() === 0);
        $this->assertDatabaseCount('admin_actions', 2);
    }

    private function project(User $owner, array $overrides = []): Project
    {
        return Project::factory()->approved()->create(['user_id' => $owner->id, ...$overrides]);
    }

    private function document(Project $project): DocumentVersion
    {
        return $project->documents()->create([
            'user_id' => $project->user_id, 'original_name' => 'PRIVATE_THESIS_NAME.pdf',
            'path' => 'theses/private.pdf', 'mime_type' => 'application/pdf', 'size' => 100, 'version' => 1,
        ]);
    }

    private function report(User $reporter, Project $project, array $overrides = []): Report
    {
        return Report::create([
            'user_id' => $reporter->id, 'reportable_type' => 'project', 'reportable_id' => $project->id,
            'reason' => 'spam', 'details' => 'Please inspect this content.', 'status' => 'pending', ...$overrides,
        ]);
    }

    private function tutorialPayload(): array
    {
        return [
            'title' => 'A clear research question', 'category' => 'Research writing',
            'excerpt' => 'A practical guide to narrowing your research question.',
            'body' => 'PRIVATE_TUTORIAL_BODY', 'youtube_url' => 'https://www.youtube.com/watch?v=Z8J1MpTMTN8',
            'video_creator' => 'A tutorial author', 'is_published' => true, 'featured' => false,
        ];
    }

    private function tutorial(User $author, array $overrides = []): Tutorial
    {
        return Tutorial::create([
            'user_id' => $author->id, 'title' => 'An ordinary guide', 'slug' => 'guide-'.bin2hex(random_bytes(5)),
            'category' => 'Research writing', 'excerpt' => 'A useful research guide.', 'body' => 'Guide contents.',
            'youtube_video_id' => 'Z8J1MpTMTN8', 'video_creator' => 'Guide author', 'is_published' => true,
            ...$overrides,
        ]);
    }

    private function invitation(User $admin, array $overrides = []): Invitation
    {
        return Invitation::create([
            'email' => 'ordinary@example.com', 'token_hash' => hash('sha256', random_bytes(32)),
            'invited_by' => $admin->id, 'expires_at' => now()->addDays(7), ...$overrides,
        ]);
    }
}
