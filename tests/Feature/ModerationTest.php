<?php

namespace Tests\Feature;

use App\Models\DocumentVersion;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\Report;
use App\Models\Tutorial;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsEvaluatorVerification;
use Tests\TestCase;

class ModerationTest extends TestCase
{
    use BuildsEvaluatorVerification, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
    }

    public function test_only_admins_can_approve_evaluators_or_open_the_private_admin_queue(): void
    {
        $student = User::factory()->create();
        $evaluator = User::factory()->pendingEvaluator()->create();

        $this->actingAs($student)->get(route('admin.index', ['section' => 'reports']))->assertForbidden();
        $this->post(route('admin.evaluators.moderate', $evaluator), ['decision' => 'approve'])->assertForbidden();
        $this->assertSame('pending', $evaluator->fresh()->evaluator_status);
    }

    public function test_admin_can_approve_complete_applications_and_must_explain_rejections(): void
    {
        $admin = User::factory()->admin()->create();
        $evaluator = User::factory()->pendingEvaluator()->create(['accepting_requests' => false]);
        $verification = $this->createVerification($evaluator);

        $this->actingAs($admin)->post(route('admin.evaluators.moderate', $evaluator), $this->approvalData($verification))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($evaluator->fresh()->isEvaluator());
        $this->assertTrue($evaluator->fresh()->accepting_requests);
        $this->assertDatabaseHas('activities', ['user_id' => $evaluator->id, 'title' => 'Evaluator application approved']);

        $this->post(route('admin.evaluators.moderate', $evaluator), ['decision' => 'reject'])
            ->assertSessionHasErrors('reason');
        $this->assertSame('approved', $evaluator->fresh()->evaluator_status);
        $this->actingAs($evaluator)->post(route('account.evaluator-verification.store'), $this->verificationData())
            ->assertRedirect()->assertSessionHasNoErrors();
        $replacement = $evaluator->fresh()->latestEvaluatorVerification()->firstOrFail();
        $this->actingAs($admin)->post(route('admin.evaluators.moderate', $evaluator), $this->approvalData($replacement, [
            'decision' => 'reject', 'reason' => 'Please provide a verifiable professional background.',
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('rejected', $evaluator->fresh()->evaluator_status);
        $this->assertFalse($evaluator->fresh()->accepting_requests);
        $this->get(route('evaluators.show', $evaluator))->assertNotFound();
    }

    public function test_incomplete_evaluator_applications_cannot_be_approved(): void
    {
        $admin = User::factory()->admin()->create();
        $evaluator = User::factory()->pendingEvaluator()->create(['bio' => null, 'expertise' => []]);
        $verification = $this->createVerification($evaluator);

        $this->actingAs($admin)->post(route('admin.evaluators.moderate', $evaluator), $this->approvalData($verification))
            ->assertSessionHasErrors('decision');
        $this->assertSame('pending', $evaluator->fresh()->evaluator_status);
    }

    public function test_professional_credentials_changes_require_new_approval_and_cannot_change_privileged_fields(): void
    {
        User::factory()->admin()->create();
        $evaluator = User::factory()->evaluator()->create();
        $payload = $this->verificationData([
            'profile' => $this->professionalProfileData($evaluator, ['headline' => 'Security architect and independent thesis reviewer']),
            'role' => 'admin', 'evaluator_status' => 'approved', 'is_suspended' => false,
        ]);

        $this->actingAs($evaluator)->post(route('account.evaluator-verification.store'), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('evaluator', $evaluator->fresh()->role);
        $this->assertSame('pending', $evaluator->fresh()->evaluator_status);
        $this->assertFalse($evaluator->fresh()->accepting_requests);
        $this->get(route('evaluators.show', $evaluator))->assertNotFound();
        $this->assertDatabaseHas('activities', ['title' => 'Evaluator evidence awaiting review']);
    }

    public function test_availability_and_capacity_changes_preserve_approved_professional_credentials(): void
    {
        $evaluator = User::factory()->evaluator()->create();

        $snapshot = $evaluator->evaluatorProfileSnapshot();
        $this->actingAs($evaluator)->put(route('account.profile.update'), [
            'name' => $evaluator->name, 'city' => $evaluator->city,
            'accepting_requests' => false, 'capacity' => 2,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $evaluator->fresh()->evaluator_status);
        $this->assertFalse($evaluator->fresh()->accepting_requests);
        $this->assertSame(2, $evaluator->fresh()->capacity);
        $this->assertSame($snapshot, $evaluator->fresh()->evaluatorProfileSnapshot());
    }

    public function test_students_cannot_apply_or_write_professional_fields_through_the_basic_profile(): void
    {
        User::factory()->admin()->create();
        $student = User::factory()->create();
        $this->actingAs($student)->put(route('account.profile.update'), [
            'name' => $student->name, 'headline' => 'Software engineer',
            'bio' => 'I help students improve database design and make their testing plans more practical.',
            'expertise' => ['Database'], 'skills' => 'SQL, Database design',
            'experience_years' => 4, 'capacity' => 2, 'review_modes' => ['online'],
            'apply' => true, 'accepting_requests' => true, 'evaluator_status' => 'approved', 'role' => 'admin',
        ])->assertRedirect()->assertSessionHasErrors(['apply', 'headline', 'expertise']);
        $this->assertSame('student', $student->fresh()->role);
        $this->assertSame('none', $student->fresh()->evaluator_status);
        $this->assertNull($student->fresh()->headline);
        $this->assertNull($student->fresh()->expertise);
        $this->assertDatabaseCount('evaluator_verifications', 0);
    }

    public function test_admin_project_approval_requires_a_document_and_rejection_requires_a_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $project = $this->project(User::factory()->create(), ['status' => 'pending']);

        $this->actingAs($admin)->post(route('admin.projects.moderate', $project), ['decision' => 'approve'])
            ->assertSessionHasErrors('decision');
        $this->post(route('admin.projects.moderate', $project), ['decision' => 'reject'])
            ->assertSessionHasErrors('reason');
        $this->post(route('admin.projects.moderate', $project), [
            'decision' => 'needs_changes', 'reason' => 'Please upload the thesis document and clarify the requested feedback.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('needs_changes', $project->fresh()->status);
    }

    public function test_hiding_a_project_requires_a_nonblank_reason_without_changing_records(): void
    {
        $admin = User::factory()->admin()->create();
        $project = $this->project(User::factory()->create(), ['moderation_note' => 'Previous moderation note.']);
        $original = $project->fresh()->getRawOriginal();

        $this->actingAs($admin);
        foreach ([[], ['reason' => ''], ['reason' => " \t "], ['reason' => '<p> </p>']] as $reason) {
            $this->post(route('admin.projects.moderate', $project), ['decision' => 'hide', ...$reason])
                ->assertRedirect()->assertSessionHasErrors('reason');

            $this->assertSame($original, $project->fresh()->getRawOriginal());
            $this->assertDatabaseCount('activities', 0);
            $this->assertDatabaseCount('admin_actions', 0);
        }
    }

    public function test_hiding_a_project_notifies_the_team_and_removes_only_its_public_listing(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $project = $this->project($owner, ['title' => 'Project requiring moderation']);
        $project->members()->attach($member);
        $unrelated = $this->project($owner, ['title' => 'Separate legitimate public project']);
        $reason = 'Please remove the unrelated advertising from this project.';

        $this->get(route('projects.index'))->assertOk()->assertSee($project->title)->assertSee($unrelated->title);
        $this->actingAs($admin)->post(route('admin.projects.moderate', $project), [
            'decision' => 'hide', 'reason' => $reason,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('hidden', $project->fresh()->status);
        $this->assertSame($reason, $project->fresh()->moderation_note);
        $this->assertSame('approved', $unrelated->fresh()->status);
        $this->assertDatabaseCount('activities', 2);
        foreach ([$owner, $member] as $recipient) {
            $this->assertDatabaseHas('activities', [
                'user_id' => $recipient->id, 'title' => 'Thesis project moderation update',
                'body' => 'Your project status is now hidden. '.$reason,
                'url' => route('account.projects.show', $project),
            ]);
        }
        $this->assertDatabaseHas('admin_actions', [
            'actor_id' => $admin->id, 'action' => 'project.hidden',
            'target_type' => 'project', 'target_id' => $project->id, 'reason' => $reason,
        ]);
        $this->get(route('projects.index'))->assertOk()->assertDontSee($project->title)->assertSee($unrelated->title);

        $this->post(route('logout'));
        $this->get(route('projects.index'))->assertOk()->assertDontSee($project->title)->assertSee($unrelated->title);
        $this->get(route('projects.show', $project))->assertNotFound();
        $this->actingAs($owner)->get(route('account.projects.show', $project))->assertOk()
            ->assertViewHas('canViewPrivate', true)->assertSee($reason);
    }

    public function test_a_hidden_project_with_a_document_can_be_restored_with_a_blank_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $project = $this->project($owner, ['status' => 'hidden', 'moderation_note' => 'Please correct the listing.']);
        DocumentVersion::factory()->create(['project_id' => $project->id]);

        $this->actingAs($admin)->post(route('admin.projects.moderate', $project), [
            'decision' => 'approve', 'reason' => '   ',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('approved', $project->fresh()->status);
        $this->assertNull($project->fresh()->moderation_note);
        $this->assertDatabaseHas('activities', ['user_id' => $owner->id, 'title' => 'Thesis project approved']);
        $this->assertDatabaseHas('admin_actions', [
            'actor_id' => $admin->id, 'action' => 'project.approved',
            'target_type' => 'project', 'target_id' => $project->id, 'reason' => null,
        ]);
        $this->get(route('projects.index'))->assertOk()->assertSee($project->title);
    }

    public function test_project_validation_returns_a_popup_and_preserves_only_the_failed_forms_note(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $project = $this->project($owner);
        $other = $this->project($owner, ['title' => 'Another project in the moderation queue']);
        $inspection = route('admin.projects.show', ['project' => $project, 'q' => 'project', 'status' => 'submitted', 'projects_page' => 1]);
        $reason = str_repeat('a', 2001);

        $response = $this->actingAs($admin)->from($inspection)->post(route('admin.projects.moderate', $project), [
            'decision' => 'hide', 'reason' => $reason, 'moderation_project_id' => (string) $project->id,
        ])->assertRedirect($inspection)->assertSessionHasErrors('reason')
            ->assertSessionHasInput('reason', $reason)->assertSessionHasInput('moderation_project_id', (string) $project->id);
        $message = session('errors')->first('reason');
        $response = $this->followRedirects($response)->assertOk();

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $popups = $xpath->query('//div[@data-toast-type="error" and @role="alert" and not(ancestor::template)]');
        $this->assertSame(1, $popups->length);
        $this->assertStringContainsString($message, $popups->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//*[@id="main"]//*[contains(concat(" ", normalize-space(@class), " "), " alert ")]')->length);
        $invalidNotes = $xpath->query('//textarea[@name="reason" and @aria-invalid="true"]');
        $this->assertSame(1, $invalidNotes->length);
        $this->assertSame('project-reason-'.$project->id, $invalidNotes->item(0)->getAttribute('id'));
        $this->assertSame($reason, $invalidNotes->item(0)->textContent);
        $this->assertNull($document->getElementById('project-reason-'.$other->id));
        $this->assertSame(1, $xpath->query('//form[@data-project-moderation]')->length);
    }

    public function test_resolving_a_project_report_hides_the_correct_content_and_keeps_reporter_private(): void
    {
        $admin = User::factory()->admin()->create();
        $reporter = User::factory()->create();
        $owner = User::factory()->create();
        $project = $this->project($owner);
        $unrelated = $this->project($owner, ['title' => 'A separate legitimate project']);
        $report = $this->report($reporter, 'project', $project->id);

        $this->actingAs($admin)->post(route('admin.reports.moderate', $report), [
            'status' => 'resolved', 'action' => 'hide_content', 'admin_note' => 'This listing contains unrelated spam.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('hidden', $project->fresh()->status);
        $this->assertSame('approved', $unrelated->fresh()->status);
        $this->assertFalse($reporter->fresh()->is_suspended);
        $this->assertFalse($owner->fresh()->is_suspended);
        $this->assertSame('resolved', $report->fresh()->status);
        $this->assertNotNull($report->fresh()->resolved_at);
        $this->assertStringNotContainsString($reporter->name, $owner->activities()->latest()->first()->body);
        $this->actingAs($reporter)->get(route('projects.show', $project))->assertNotFound();
    }

    public function test_report_actions_apply_on_resolution_and_suspend_the_reported_account(): void
    {
        $admin = User::factory()->admin()->create();
        $reporter = User::factory()->create();
        $evaluator = User::factory()->evaluator()->create();
        $report = $this->report($reporter, 'user', $evaluator->id);
        $payload = ['status' => 'under_review', 'action' => 'suspend_account', 'admin_note' => 'Checking repeated abusive conduct.'];

        $this->actingAs($admin)->post(route('admin.reports.moderate', $report), $payload)->assertSessionHasNoErrors();
        $this->assertFalse($evaluator->fresh()->is_suspended);
        $this->post(route('admin.reports.moderate', $report), [...$payload, 'status' => 'resolved'])->assertSessionHasNoErrors();
        $this->assertTrue($evaluator->fresh()->is_suspended);
        $this->assertFalse($evaluator->fresh()->accepting_requests);
        $this->assertFalse($reporter->fresh()->is_suspended);
        $this->assertFalse($admin->fresh()->is_suspended);
    }

    public function test_administrator_accounts_and_self_cannot_be_suspended_through_reports_or_user_controls(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $reporter = User::factory()->create();
        $report = $this->report($reporter, 'user', $otherAdmin->id);

        $this->actingAs($admin)->post(route('admin.reports.moderate', $report), [
            'status' => 'resolved', 'action' => 'suspend_account', 'admin_note' => 'This action should be prohibited.',
        ])->assertSessionHasErrors('action');
        $this->post(route('admin.users.toggle', $otherAdmin), ['action' => 'suspend'])->assertForbidden();
        $this->post(route('admin.users.toggle', $admin), ['action' => 'suspend'])->assertForbidden();
        $this->assertFalse($otherAdmin->fresh()->is_suspended);
        $this->assertFalse($admin->fresh()->is_suspended);
        $this->assertSame('pending', $report->fresh()->status);
    }

    public function test_tutorials_can_be_published_edited_saved_unpublished_and_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->create();
        $article = [
            'title' => 'Make your research problem specific', 'category' => 'Research writing',
            'excerpt' => 'A practical guide to narrowing the scope of your thesis.',
            'body' => '<p>Describe the real difficulty and who experiences it. Connect each objective to evidence you can collect.</p>',
            'youtube_url' => 'https://youtu.be/Z8J1MpTMTN8?si=test-share',
            'video_creator' => 'Research tutorial creator', 'is_published' => true, 'featured' => true,
        ];
        $this->actingAs($admin)->post(route('admin.tutorials.store'), $article)->assertSessionHasNoErrors();
        $tutorial = Tutorial::firstOrFail();
        $slug = $tutorial->slug;
        $this->assertStringNotContainsString('<p>', $tutorial->body);
        $this->get(route('tutorials.show', $tutorial))->assertOk()->assertSee($article['title']);

        $this->actingAs($student)->post(route('account.bookmarks.toggle', $tutorial))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tutorial_user', ['user_id' => $student->id, 'tutorial_id' => $tutorial->id]);
        $this->get(route('account.dashboard', ['tab' => 'bookmarks']))->assertOk()->assertSee($tutorial->title);

        $this->actingAs($admin)->put(route('admin.tutorials.update', $tutorial), [
            ...$article, 'title' => 'A clearer research problem', 'is_published' => false,
        ])->assertSessionHasNoErrors();
        $this->assertSame($slug, $tutorial->fresh()->slug);
        $this->actingAs($student)->get(route('tutorials.show', $tutorial))->assertNotFound();
        $this->post(route('account.bookmarks.toggle', $tutorial))->assertNotFound();
        $this->get(route('account.dashboard', ['tab' => 'bookmarks']))->assertDontSee('A clearer research problem');

        $this->actingAs($admin)->delete(route('admin.tutorials.destroy', $tutorial))->assertRedirect();
        $this->assertDatabaseMissing('tutorials', ['id' => $tutorial->id]);
        $this->assertDatabaseMissing('tutorial_user', ['tutorial_id' => $tutorial->id]);
    }

    public function test_invitation_tokens_are_hashed_expire_and_are_bound_to_the_invited_email(): void
    {
        $this->freezeTime();
        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->post(route('admin.invitations.store'), ['email' => 'Expert@Example.com']);
        $response->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('invitation_url');
        parse_str(parse_url(session('invitation_url'), PHP_URL_QUERY), $query);
        $token = $query['invite'];
        $invitation = Invitation::firstOrFail();
        $this->assertSame('expert@example.com', $invitation->email);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame(now()->addDays(7)->timestamp, $invitation->expires_at->timestamp);
        $this->assertArrayNotHasKey('token_hash', $invitation->toArray());

        $this->post(route('logout'));
        $registration = [
            'name' => 'Invited Expert', 'email' => 'different@example.com',
            'password' => 'LongEnough!Password2026', 'password_confirmation' => 'LongEnough!Password2026',
            'role' => 'student', 'terms' => '1', 'invite' => $token,
        ];
        $this->post(route('register'), $registration)->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'different@example.com']);
        $this->post(route('register'), [...$registration, 'email' => $invitation->email])
            ->assertRedirect(route('account.evaluator-verification.show'))->assertSessionHasNoErrors();
        $invited = User::where('email', $invitation->email)->firstOrFail();
        $this->assertSame('evaluator', $invited->role);
        $this->assertSame('pending', $invited->evaluator_status);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_expired_or_replaced_invitation_tokens_cannot_be_used(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.invitations.store'), ['email' => 'future@example.com']);
        parse_str(parse_url(session('invitation_url'), PHP_URL_QUERY), $query);
        $firstToken = $query['invite'];
        $this->post(route('admin.invitations.store'), ['email' => 'future@example.com']);
        parse_str(parse_url(session('invitation_url'), PHP_URL_QUERY), $query);
        $latestToken = $query['invite'];
        $this->assertNotSame($firstToken, $latestToken);
        $this->assertTrue(Invitation::where('token_hash', hash('sha256', $firstToken))->firstOrFail()->expires_at->isPast());
        $this->post(route('logout'));
        $payload = [
            'name' => 'Future Expert', 'email' => 'future@example.com',
            'password' => 'LongEnough!Password2026', 'password_confirmation' => 'LongEnough!Password2026',
            'role' => 'evaluator', 'terms' => '1', 'invite' => $firstToken,
        ];
        $this->post(route('register'), $payload)->assertSessionHasErrors('email');
        $this->travel(8)->days();
        $this->post(route('register'), [...$payload, 'invite' => $latestToken])->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'future@example.com']);
    }

    public function test_notifications_can_only_be_marked_read_by_their_owner(): void
    {
        $student = User::factory()->create();
        $other = User::factory()->create();
        $activity = $other->notifyActivity('A private update', 'Only its owner may alter it.', route('account.dashboard'));

        $this->actingAs($student)->post(route('account.notifications.read', $activity))->assertForbidden();
        $this->assertNull($activity->fresh()->read_at);
        $this->actingAs($other)->post(route('account.notifications.readall'))->assertRedirect();
        $this->assertNotNull($activity->fresh()->read_at);
    }

    public function test_all_admin_sections_and_account_tabs_render_with_community_data(): void
    {
        $this->seed(DemoSeeder::class);
        $admin = User::where('email', 'admin@analista.test')->firstOrFail();
        $this->actingAs($admin);
        foreach (['overview', 'evaluators', 'projects', 'reports', 'users', 'tutorials', 'invitations'] as $section) {
            $this->get(route('admin.index', ['section' => $section]))->assertOk();
        }

        foreach (['student@analista.test', 'evaluator@analista.test'] as $email) {
            $this->actingAs(User::where('email', $email)->firstOrFail());
            foreach (['reviews', 'notifications', 'bookmarks'] as $tab) {
                $this->get(route('account.dashboard', ['tab' => $tab]))->assertOk();
            }
            $this->get(route('account.projects'))->assertOk();
            $this->get(route('account.profile'))->assertOk();
        }
    }

    private function project(User $owner, array $overrides = []): Project
    {
        return Project::create(array_replace([
            'user_id' => $owner->id, 'title' => 'A community thesis project', 'summary' => 'A focused study of a real student problem.',
            'category' => 'Web', 'feedback_needed' => 'Please check our research objectives and database design.',
            'status' => 'approved', 'is_public' => true,
        ], $overrides));
    }

    private function report(User $reporter, string $type, int $id): Report
    {
        return Report::create([
            'user_id' => $reporter->id, 'reportable_type' => $type, 'reportable_id' => $id,
            'reason' => 'spam', 'details' => 'Please inspect this content for spam.', 'status' => 'pending',
        ]);
    }
}
