<?php

namespace Tests\Feature;

use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsEvaluatorVerification;
use Tests\TestCase;

class AdminReviewMonitoringTest extends TestCase
{
    use BuildsEvaluatorVerification, RefreshDatabase;

    private string $ownedViews;

    private string $ownedDisk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $token = 'admin-review-monitoring-'.bin2hex(random_bytes(8));
        $this->ownedViews = storage_path('framework/testing/'.$token);
        (new Filesystem)->ensureDirectoryExists($this->ownedViews);
        config(['view.compiled' => $this->ownedViews, 'app.url' => 'http://localhost', 'app.timezone' => 'Asia/Manila']);
        $this->app['url']->forceRootUrl('http://localhost');
        $this->app->forgetInstance('blade.compiler');
        $this->app['view.engine.resolver']->forget('blade');
        $this->ownedDisk = storage_path('framework/testing/disks/'.$token);
        Storage::set('local', Storage::fake($token));
        $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00', 'Asia/Manila'));
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

    public function test_only_active_administrators_can_open_review_monitoring(): void
    {
        $review = $this->review(['status' => 'accepted', 'agreed_date' => '2026-10-28']);
        $url = route('admin.index', ['section' => 'reviews', 'status' => 'overdue']);
        $this->get($url)->assertRedirect(route('login'))->assertDontSee($review->project->title);
        foreach ([$review->requester, $review->evaluator, User::factory()->create()] as $viewer) {
            $this->actingAs($viewer)->get($url)->assertForbidden()->assertDontSee($review->project->title);
        }
        $admin = User::factory()->admin()->create();
        $suspended = User::factory()->admin()->create(['is_suspended' => true]);
        $this->actingAs($suspended)->get($url)->assertRedirect(route('login'))->assertDontSee($review->project->title);
        $this->actingAs($admin)->get($url)->assertOk()->assertSee($review->project->title);
    }

    public function test_overdue_uses_whole_calendar_days_in_the_application_timezone_without_changing_status(): void
    {
        // This instant is still October 30 in UTC, but October 31 in Manila.
        $this->travelTo(CarbonImmutable::parse('2026-10-30 16:30:00', 'UTC'));
        foreach ([
            ['accepted', '2026-10-30', true, 1],
            ['accepted', '2026-10-28', true, 3],
            ['accepted', '2026-10-31', false, 0],
            ['accepted', '2026-11-01', false, 0],
            ['accepted', null, false, 0],
            ['pending', '2026-10-28', false, 0],
            ['declined', '2026-10-28', false, 0],
            ['completed', '2026-10-28', false, 0],
        ] as [$status, $agreedDate, $late, $days]) {
            $review = $this->review(['status' => $status, 'agreed_date' => $agreedDate]);
            $before = $review->fresh()->getRawOriginal();
            $this->assertSame($late, $review->isOverdue(), $status.' / '.($agreedDate ?? 'no date'));
            $this->assertSame($days, $review->daysOverdue());
            $this->assertSame($before, $review->fresh()->getRawOriginal());
        }
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.index', ['section' => 'reviews', 'status' => 'overdue']))
            ->assertOk()->assertViewHas('reviews', fn ($reviews) => $reviews->total() === 2)
            ->assertViewHas('stats', fn ($stats) => $stats['overdue_reviews'] === 2);
        $this->assertDatabaseCount('activities', 0);
        $this->assertDatabaseCount('admin_actions', 0);
    }

    public function test_status_filters_and_overdue_ordering_match_the_overdue_counter(): void
    {
        $oldest = $this->review(['status' => 'accepted', 'agreed_date' => '2026-10-25']);
        $recent = $this->review(['status' => 'accepted', 'agreed_date' => '2026-10-29']);
        $today = $this->review(['status' => 'accepted', 'agreed_date' => '2026-10-30']);
        $future = $this->review(['status' => 'accepted', 'agreed_date' => '2026-11-01']);
        $undated = $this->review(['status' => 'accepted', 'agreed_date' => null]);
        $pending = $this->review(['status' => 'pending', 'agreed_date' => null]);
        $completed = $this->review(['status' => 'completed', 'agreed_date' => '2026-10-20']);
        $declined = $this->review(['status' => 'declined', 'agreed_date' => '2026-10-20']);
        $this->actingAs(User::factory()->admin()->create());
        $response = $this->get(route('admin.index', ['section' => 'reviews']));
        $response->assertOk()->assertViewHas('reviews', fn ($reviews) => $reviews->total() === 8)
            ->assertViewHas('stats', fn ($stats) => $stats['overdue_reviews'] === 2);
        $this->assertSame([$oldest->id, $recent->id], $response->viewData('reviews')->take(2)->modelKeys());
        $this->assertEqualsCanonicalizing([$today->id, $future->id, $undated->id], $response->viewData('reviews')->slice(2, 3)->modelKeys());
        foreach ([
            ['overdue', [$oldest->id, $recent->id]],
            ['ongoing', [$oldest->id, $recent->id, $today->id, $future->id, $undated->id]],
            ['pending', [$pending->id]],
            ['completed', [$completed->id]],
            ['declined', [$declined->id]],
        ] as [$status, $expected]) {
            $response = $this->get(route('admin.index', ['section' => 'reviews', 'status' => $status]));
            $response->assertOk()->assertViewHas('stats', fn ($stats) => $stats['overdue_reviews'] === 2);
            $this->assertEqualsCanonicalizing($expected, $response->viewData('reviews')->modelKeys(), $status);
        }
    }

    public function test_monitoring_searches_only_project_and_evaluator_metadata_and_omits_private_materials(): void
    {
        $review = $this->review([
            'status' => 'completed', 'message' => 'PRIVATE_REQUEST_MESSAGE', 'final_summary' => 'PRIVATE_FINAL_SUMMARY',
            'output_path' => 'review-outputs/1/'.str_repeat('A', 40).'.pdf', 'output_original_name' => 'PRIVATE_OUTPUT_NAME.pdf',
        ]);
        $review->project->update(['title' => 'Cedar thesis metadata', 'summary' => 'PRIVATE_THESIS_SUMMARY']);
        $review->evaluator->update(['name' => 'Cedar Evaluator', 'email' => 'private-evaluator@example.com']);
        $this->createVerification($review->evaluator, ['sample_review' => 'PRIVATE_VERIFICATION_REVIEW', 'evidence_url' => 'https://private.example.com/PRIVATE_EVIDENCE_LINK']);
        $review->feedback()->create(['user_id' => $review->evaluator_id, 'body' => 'PRIVATE_FEEDBACK_BODY']);
        $review->document->update(['original_name' => 'PRIVATE_THESIS_FILENAME.pdf']);
        $other = $this->review(['status' => 'completed']);
        $this->actingAs(User::factory()->admin()->create());
        foreach (['Cedar thesis', 'Cedar Evaluator', (string) $review->id] as $query) {
            $this->get(route('admin.index', ['section' => 'reviews', 'q' => $query]))->assertOk()
                ->assertViewHas('reviews', fn ($reviews) => $reviews->modelKeys() === [$review->id]);
        }
        $response = $this->get(route('admin.index', ['section' => 'reviews']));
        $response->assertOk();
        foreach (['PRIVATE_REQUEST_MESSAGE', 'PRIVATE_FINAL_SUMMARY', 'PRIVATE_OUTPUT_NAME', 'PRIVATE_THESIS_SUMMARY',
            'PRIVATE_VERIFICATION_REVIEW', 'PRIVATE_EVIDENCE_LINK', 'PRIVATE_FEEDBACK_BODY', 'PRIVATE_THESIS_FILENAME', 'private-evaluator@example.com'] as $secret) {
            $response->assertDontSee($secret);
            $this->get(route('admin.index', ['section' => 'reviews', 'q' => $secret]))->assertOk()
                ->assertViewHas('reviews', fn ($reviews) => $reviews->total() === 0);
        }
        foreach ($response->viewData('reviews') as $row) {
            $this->assertFalse($row->relationLoaded('feedback'));
            foreach (['message', 'final_summary', 'output_path', 'output_original_name'] as $field) {
                $this->assertArrayNotHasKey($field, $row->getAttributes());
            }
            $this->assertArrayNotHasKey('email', $row->evaluator->getAttributes());
        }
        $this->assertSame('completed', $other->fresh()->status);
    }

    public function test_review_queries_validate_bounds_and_treat_wildcards_and_sql_text_as_literals(): void
    {
        $review = $this->review(['status' => 'accepted']);
        $this->actingAs(User::factory()->admin()->create());
        foreach ([['q' => str_repeat('a', 201)], ['q' => ['unexpected']], ['status' => 'approved'], ['status' => ['ongoing']]] as $query) {
            $this->getJson(route('admin.index', ['section' => 'reviews', ...$query]))->assertUnprocessable();
        }
        foreach (['%', '_', "' OR 1=1 --"] as $query) {
            $this->get(route('admin.index', ['section' => 'reviews', 'q' => $query]))->assertOk()
                ->assertViewHas('reviews', fn ($reviews) => $reviews->total() === 0);
        }
        $this->assertSame('accepted', $review->fresh()->status);
    }

    public function test_filter_pagination_and_inspection_are_read_only_for_records_files_notifications_and_history(): void
    {
        $review = $this->review(['status' => 'accepted', 'agreed_date' => '2026-10-29']);
        $review->project->update(['title' => 'Pagination Orchard thesis']);
        $review->document->update(['path' => 'theses/monitoring-original.pdf']);
        $bytes = "%PDF-1.4\nMonitoring must preserve these original bytes.\n%%EOF";
        Storage::disk('local')->put($review->document->path, $bytes);
        foreach (range(1, 22) as $number) {
            $this->review(['status' => 'accepted', 'agreed_date' => '2026-10-29'], $review->project, $review->evaluator, $review->document);
        }
        $snapshots = [];
        foreach (['review_requests', 'projects', 'users', 'document_versions', 'activities', 'admin_actions'] as $table) {
            $snapshots[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $admin = User::factory()->admin()->create();
        $snapshots['users'] = DB::table('users')->orderBy('id')->get()->toJson();
        $response = $this->actingAs($admin)->get(route('admin.index', ['section' => 'reviews', 'status' => 'ongoing', 'q' => 'Orchard']));
        $response->assertOk()->assertViewHas('reviews', fn ($reviews) => $reviews->total() === 23);
        $nextUrl = $response->viewData('reviews')->nextPageUrl();
        parse_str(parse_url($nextUrl, PHP_URL_QUERY), $query);
        $this->assertSame('reviews', $query['section']);
        $this->assertSame('ongoing', $query['status']);
        $this->assertSame('Orchard', $query['q']);
        $this->assertSame('2', $query['reviews_page']);
        $this->get($nextUrl)->assertOk()->assertViewHas('reviews', fn ($reviews) => $reviews->count() === 3);
        $this->get(route('reviews.show', $review))->assertOk();
        foreach ($snapshots as $table => $before) {
            $this->assertSame($before, DB::table($table)->orderBy('id')->get()->toJson(), $table.' was changed by inspection');
        }
        $this->assertSame($bytes, Storage::disk('local')->get($review->document->path));
        $this->assertSame([$review->document->path], Storage::disk('local')->allFiles());
    }

    public function test_view_review_uses_existing_private_review_authorization_and_exact_document_version(): void
    {
        $review = $this->review(['status' => 'completed']);
        $review->feedback()->create(['user_id' => $review->evaluator_id, 'body' => 'Private recommendations for this assigned version.']);
        $otherVersion = DocumentVersion::factory()->create(['project_id' => $review->project_id, 'version' => 2]);
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $review->project->members()->attach($member);
        $this->actingAs($admin)->get(route('admin.index', ['section' => 'reviews']))->assertOk()
            ->assertSee(route('reviews.show', $review), false);
        foreach ([$admin, $review->project->owner, $member, $review->evaluator] as $viewer) {
            $this->actingAs($viewer)->get(route('reviews.show', $review))->assertOk()
                ->assertViewHas('canViewDocument', true)
                ->assertViewHas('review', fn ($visible) => $visible->document->id === $review->document_version_id)
                ->assertSee('Private recommendations for this assigned version.');
        }
        foreach ([User::factory()->create(), User::factory()->evaluator()->create()] as $unrelated) {
            $this->actingAs($unrelated)->get(route('reviews.show', $review))->assertForbidden()
                ->assertDontSee('Private recommendations for this assigned version.');
        }
        $this->assertNotSame($otherVersion->id, $review->fresh()->document_version_id);
        $this->assertDatabaseCount('activities', 0);
        $this->assertDatabaseCount('admin_actions', 0);
    }

    private function review(array $overrides = [], ?Project $project = null, ?User $evaluator = null, ?DocumentVersion $document = null): ReviewRequest
    {
        $project ??= Project::factory()->approved()->create(['title' => 'An ordinary thesis project']);
        $evaluator ??= User::factory()->evaluator()->create(['name' => 'An ordinary evaluator']);
        $document ??= DocumentVersion::factory()->create(['project_id' => $project->id, 'user_id' => $project->user_id, 'version' => 1]);

        return ReviewRequest::factory()->create([
            'project_id' => $project->id, 'evaluator_id' => $evaluator->id,
            'document_version_id' => $document->id, 'requested_by' => $project->user_id, ...$overrides,
        ]);
    }
}
