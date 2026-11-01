<?php

namespace Tests\Feature;

use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectReviewChooserTest extends TestCase
{
    use RefreshDatabase;

    private string $ownedViews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->ownedViews = storage_path('framework/testing/project-review-chooser-'.bin2hex(random_bytes(8)));
        (new Filesystem)->ensureDirectoryExists($this->ownedViews);
        config(['view.compiled' => $this->ownedViews, 'app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
        $this->app->forgetInstance('blade.compiler');
        $this->app['view.engine.resolver']->forget('blade');
    }

    protected function tearDown(): void
    {
        $path = $this->ownedViews ?? null;
        $testingRoot = realpath(storage_path('framework/testing'));
        try {
            parent::tearDown();
        } finally {
            $resolved = $path ? realpath($path) : false;
            if ($testingRoot !== false && $resolved !== false
                && str_starts_with(strtolower($resolved), strtolower(rtrim($testingRoot, '\\/').DIRECTORY_SEPARATOR))) {
                (new Filesystem)->deleteDirectory($resolved);
            }
        }
    }

    public function test_only_active_student_owners_and_current_group_members_can_open_the_project_review_chooser(): void
    {
        $project = Project::factory()->approved()->create();
        $member = User::factory()->create();
        $project->members()->attach($member);
        $review = ReviewRequest::factory()->accepted()->create(['project_id' => $project->id]);
        $url = route('account.projects.reviews', $project);
        $this->get($url)->assertRedirect(route('login'));
        foreach ([$project->owner, $member] as $actor) {
            $this->actingAs($actor)->get($url)->assertOk()->assertViewIs('account.project-reviews');
        }
        $project->members()->detach($member);
        $admin = User::factory()->admin()->create();
        $project->members()->attach([$admin->id, $review->evaluator_id]);
        foreach ([$member, User::factory()->create(), $review->evaluator, $admin] as $actor) {
            $this->actingAs($actor)->get($url)->assertForbidden()->assertDontSee($project->title);
        }
        $project->owner->update(['is_suspended' => true]);
        $this->actingAs($project->owner)->get($url)->assertRedirect(route('login'));
        $this->assertDatabaseCount('admin_actions', 0);
        $this->assertDatabaseCount('sensitive_file_accesses', 0);
    }

    public function test_chooser_is_scoped_to_one_project_and_keeps_repeated_evaluators_as_separate_versioned_reviews(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->approved()->create(['user_id' => $owner->id]);
        $document = DocumentVersion::factory()->create(['project_id' => $project->id, 'version' => 1]);
        $revision = DocumentVersion::factory()->create(['project_id' => $project->id, 'version' => 2]);
        $evaluator = User::factory()->evaluator()->create(['name' => 'Assigned Thesis Evaluator']);
        $completed = ReviewRequest::factory()->completed()->create([
            'project_id' => $project->id, 'document_version_id' => $document->id, 'evaluator_id' => $evaluator->id,
            'message' => 'PRIVATE_REVIEW_MESSAGE', 'final_summary' => 'PRIVATE_FINAL_SUMMARY',
            'output_original_name' => 'PRIVATE_OUTPUT_NAME.pdf', 'output_path' => 'review-outputs/private-output.pdf',
        ]);
        $accepted = ReviewRequest::factory()->accepted()->create([
            'project_id' => $project->id, 'document_version_id' => $revision->id, 'evaluator_id' => $evaluator->id,
        ]);
        $pending = ReviewRequest::factory()->create(['project_id' => $project->id, 'document_version_id' => $revision->id]);
        $declined = ReviewRequest::factory()->create(['project_id' => $project->id, 'document_version_id' => $revision->id, 'status' => 'declined']);
        $completed->feedback()->create(['user_id' => $evaluator->id, 'body' => 'PRIVATE_FEEDBACK_BODY']);
        $other = Project::factory()->approved()->create(['user_id' => $owner->id, 'title' => 'OTHER_PROJECT_ONLY']);
        $foreign = ReviewRequest::factory()->completed()->create(['project_id' => $other->id]);
        $this->actingAs($owner);
        $response = $this->get(route('account.projects.reviews', [
            'project' => $project, 'project_id' => $other->id, 'review_id' => $foreign->id, 'evaluator_id' => $foreign->evaluator_id,
        ]))->assertOk()->assertSee('Assigned Thesis Evaluator')->assertDontSee('OTHER_PROJECT_ONLY');
        $this->assertSame([$completed->id, $accepted->id, $pending->id, $declined->id], $response->viewData('reviews')->modelKeys());
        $this->assertSame(2, $response->viewData('reviews')->where('evaluator_id', $evaluator->id)->count());
        foreach ($response->viewData('reviews') as $review) {
            foreach (['message', 'final_summary', 'output_path', 'output_original_name', 'output_size'] as $field) {
                $this->assertArrayNotHasKey($field, $review->getAttributes());
            }
            $this->assertFalse($review->relationLoaded('feedback'));
            $this->assertEqualsCanonicalizing(['id', 'version'], array_keys($review->document->getAttributes()));
            $this->assertEqualsCanonicalizing(['id', 'name', 'headline', 'expertise', 'profile_photo_path'], array_keys($review->evaluator->getAttributes()));
            $response->assertSee(route('reviews.show', $review).($review->status === 'completed' ? '#review-summary' : ''), false);
        }
        foreach (['PRIVATE_REVIEW_MESSAGE', 'PRIVATE_FINAL_SUMMARY', 'PRIVATE_OUTPUT_NAME', 'PRIVATE_FEEDBACK_BODY'] as $private) {
            $response->assertDontSee($private);
        }
        $this->get(route('reviews.show', $completed))->assertOk()->assertSee('PRIVATE_FINAL_SUMMARY')
            ->assertSee(route('account.projects.reviews', $project), false);
        $this->get(route('reviews.show', $foreign))->assertOk();
    }

    public function test_empty_chooser_is_safe_and_review_pagination_stays_within_the_selected_project_without_writes(): void
    {
        $project = Project::factory()->approved()->create();
        $this->actingAs($project->owner)->get(route('account.projects.reviews', $project))->assertOk()
            ->assertViewHas('reviews', fn ($reviews) => $reviews->total() === 0);
        $document = DocumentVersion::factory()->create(['project_id' => $project->id]);
        $evaluator = User::factory()->evaluator()->create();
        foreach (range(1, 14) as $index) {
            ReviewRequest::factory()->completed()->create([
                'project_id' => $project->id, 'document_version_id' => $document->id, 'evaluator_id' => $evaluator->id,
            ]);
        }
        $before = [];
        foreach (['users', 'projects', 'review_requests', 'document_versions', 'activities', 'admin_actions', 'sensitive_file_accesses'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $response = $this->get(route('account.projects.reviews', $project))->assertOk();
        $this->assertSame(14, $response->viewData('reviews')->total());
        $this->assertCount(12, $response->viewData('reviews'));
        $next = $response->viewData('reviews')->nextPageUrl();
        $this->assertStringContainsString('/account/projects/'.$project->id.'/reviews?', $next);
        $this->get($next)->assertOk()->assertViewHas('reviews', fn ($reviews) => $reviews->count() === 2);
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson(), $table.' was changed by browsing');
        }
    }

    public function test_changing_membership_revokes_chooser_access_and_cannot_be_bypassed_by_public_pdf_visibility(): void
    {
        $project = Project::factory()->publicListing()->create(['pdf_access' => Project::PDF_ACCESS_APPROVED_EVALUATORS]);
        $member = User::factory()->create();
        $project->members()->attach($member);
        $this->actingAs($member)->get(route('account.projects.reviews', $project))->assertOk();
        $project->members()->detach($member);
        $this->get(route('account.projects.reviews', $project))->assertForbidden();
        $this->actingAs(User::factory()->evaluator()->create())->get(route('account.projects.reviews', $project))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('account.projects.reviews', $project))->assertForbidden();
    }
}
