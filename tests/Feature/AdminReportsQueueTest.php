<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminReportsQueueTest extends TestCase
{
    use RefreshDatabase;

    private string $ownedViews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->ownedViews = storage_path('framework/testing/admin-reports-'.bin2hex(random_bytes(8)));
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

    public function test_report_queue_defaults_to_open_and_displays_only_compact_subject_metadata(): void
    {
        $admin = User::factory()->admin()->create();
        $reporter = User::factory()->create(['name' => 'PRIVATE_REPORTER_NAME']);
        $project = Project::factory()->approved()->create(['title' => 'Campus thesis project', 'summary' => 'PRIVATE_THESIS_SUMMARY']);
        $pending = $this->report($reporter, 'project', $project->id);
        $account = User::factory()->evaluator()->create(['name' => 'Reported evaluator']);
        $inReview = $this->report($reporter, 'user', $account->id, ['status' => 'under_review']);
        $resolved = $this->report($reporter, 'project', $project->id, ['status' => 'resolved']);
        $response = $this->actingAs($admin)->get(route('admin.index', ['section' => 'reports']));
        $response->assertOk()->assertViewHas('filters', fn ($filters) => $filters['status'] === 'open')
            ->assertSee('Campus thesis project')->assertSee('Reported evaluator')
            ->assertDontSee('PRIVATE_REPORTER_NAME')->assertDontSee('PRIVATE_REPORT_DETAILS')
            ->assertDontSee('PRIVATE_ADMIN_NOTE')->assertDontSee('PRIVATE_THESIS_SUMMARY');
        $this->assertEqualsCanonicalizing([$pending->id, $inReview->id], $response->viewData('reports')->modelKeys());
        foreach ($response->viewData('reports') as $row) {
            $this->assertFalse($row->relationLoaded('reporter'));
            $this->assertArrayNotHasKey('details', $row->getAttributes());
            $this->assertArrayNotHasKey('admin_note', $row->getAttributes());
            $this->assertArrayNotHasKey('user_id', $row->getAttributes());
        }
        $subjects = $response->viewData('reportSubjects');
        $this->assertEqualsCanonicalizing(['id', 'title'], array_keys($subjects['project'][$project->id]->getAttributes()));
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($subjects['user'][$account->id]->getAttributes()));
        $this->get(route('admin.index', ['section' => 'reports', 'status' => '']))->assertOk()
            ->assertViewHas('reports', fn ($reports) => $reports->total() === 3);
        $this->get(route('admin.index', ['section' => 'reports', 'status' => 'resolved']))->assertOk()
            ->assertViewHas('reports', fn ($reports) => $reports->modelKeys() === [$resolved->id]);
        $this->assertDatabaseCount('admin_actions', 0);
    }

    public function test_queue_search_status_and_ten_item_pagination_keep_validated_local_details_context(): void
    {
        $reporter = User::factory()->create(['name' => 'Cedar Reporter']);
        $project = Project::factory()->approved()->create();
        foreach (range(1, 12) as $number) {
            $this->report($reporter, 'project', $project->id, ['status' => 'under_review']);
        }
        $this->report($reporter, 'project', $project->id, ['status' => 'resolved']);
        $this->actingAs(User::factory()->admin()->create());
        $response = $this->get(route('admin.index', ['section' => 'reports', 'q' => 'Cedar', 'status' => 'under_review']));
        $response->assertOk()->assertViewHas('reports', fn ($reports) => $reports->count() === 10 && $reports->total() === 12);
        $next = $response->viewData('reports')->nextPageUrl();
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame(['section' => 'reports', 'q' => 'Cedar', 'status' => 'under_review', 'reports_page' => '2'], $query);
        $second = $this->get($next)->assertOk()->assertViewHas('reports', fn ($reports) => $reports->count() === 2);
        $report = $second->viewData('reports')->first();
        $details = $this->get(route('admin.reports.show', [
            'report' => $report, 'q' => 'Cedar', 'status' => 'under_review', 'reports_page' => 2,
            'return_url' => 'https://untrusted.example.org/path',
        ]))->assertOk();
        $this->assertSame(['status' => 'under_review', 'q' => 'Cedar', 'reports_page' => 2], $details->viewData('listFilters'));
        parse_str(parse_url($details->viewData('backUrl'), PHP_URL_QUERY), $back);
        $this->assertSame(['section' => 'reports', 'status' => 'under_review', 'q' => 'Cedar', 'reports_page' => '2'], $back);
        $details->assertDontSee('https://untrusted.example.org/path');
        foreach (['PRIVATE_REPORT_DETAILS', '%', "' OR 1=1 --"] as $q) {
            $this->get(route('admin.index', ['section' => 'reports', 'q' => $q]))->assertOk()
                ->assertViewHas('reports', fn ($reports) => $reports->total() === 0);
        }
        $this->get(route('admin.reports.show', ['report' => $report, 'status' => '']))->assertOk()
            ->assertViewHas('listFilters', fn ($filters) => $filters['status'] === '');
        $this->get(route('admin.reports.show', $report))->assertOk()
            ->assertViewHas('listFilters', fn ($filters) => $filters['status'] === 'open');
        foreach ([['q' => str_repeat('a', 201)], ['status' => 'approved'], ['reports_page' => -1], ['reports_page' => ['bad']]] as $invalid) {
            $this->getJson(route('admin.reports.show', ['report' => $report, ...$invalid]))->assertUnprocessable();
        }
    }

    public function test_report_details_are_private_to_active_administrators_and_escape_reported_content(): void
    {
        $reporter = User::factory()->create(['name' => '<b>Private reporter</b>']);
        $project = Project::factory()->approved()->create(['title' => '<script>alert("subject")</script>']);
        $report = $this->report($reporter, 'project', $project->id, ['details' => '<script>alert("details")</script>']);
        $url = route('admin.reports.show', $report);
        $this->get($url)->assertRedirect(route('login'));
        foreach ([$reporter, $project->owner, User::factory()->evaluator()->create()] as $viewer) {
            $this->actingAs($viewer)->get($url)->assertForbidden()->assertDontSee('PRIVATE_ADMIN_NOTE');
        }
        $this->actingAs(User::factory()->admin()->create(['is_suspended' => true]))->get($url)->assertRedirect(route('login'));
        $response = $this->actingAs(User::factory()->admin()->create())->get($url)->assertOk()
            ->assertViewHas('subject', fn ($subject) => $subject->id === $project->id)
            ->assertSee(e($report->details), false)->assertDontSee($report->details, false)
            ->assertSee(e($project->title), false)->assertDontSee($project->title, false)
            ->assertSee(e($reporter->name), false)->assertDontSee($reporter->name, false)
            ->assertSee('PRIVATE_ADMIN_NOTE');
        $this->assertTrue($response->viewData('report')->relationLoaded('reporter'));
        $this->assertDatabaseCount('admin_actions', 0);
        $this->assertDatabaseCount('activities', 0);
    }

    public function test_deleted_subject_reports_still_open_and_can_be_dismissed_without_mutating_other_content(): void
    {
        $reporter = User::factory()->create();
        $project = Project::factory()->approved()->create();
        $report = $this->report($reporter, 'project', $project->id);
        $project->delete();
        $admin = User::factory()->admin()->create();
        $url = route('admin.reports.show', $report);
        $this->actingAs($admin)->get($url)->assertOk()->assertViewHas('subject', null);
        $this->from($url)->post(route('admin.reports.moderate', $report), [
            'status' => 'resolved', 'action' => 'dismiss', 'admin_note' => 'Reported content has already been removed.',
        ])->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertSame('resolved', $report->fresh()->status);
        $this->assertDatabaseHas('admin_actions', ['actor_id' => $admin->id, 'action' => 'report.resolved', 'target_id' => $report->id]);
        $this->assertDatabaseCount('projects', 0);
        $this->get($url)->assertOk()->assertViewHas('subject', null);
    }

    public function test_detail_decisions_preserve_validation_old_input_and_existing_moderation_audits(): void
    {
        $reporter = User::factory()->create();
        $project = Project::factory()->approved()->create();
        $report = $this->report($reporter, 'project', $project->id, ['admin_note' => null]);
        $admin = User::factory()->admin()->create();
        $detailsUrl = route('admin.reports.show', ['report' => $report, 'status' => 'under_review', 'q' => 'spam']);
        $postUrl = route('admin.reports.moderate', ['report' => $report, 'status' => 'under_review', 'q' => 'spam']);
        $this->actingAs($admin)->get($detailsUrl)->assertOk()->assertSee(e($postUrl), false);
        $this->from($detailsUrl)->post($postUrl, [
            'report_id' => $report->id, 'status' => 'resolved', 'action' => 'hide_content', 'admin_note' => '',
        ])->assertRedirect($detailsUrl)->assertSessionHasErrors('admin_note');
        $this->get($detailsUrl)->assertOk()->assertSee('value="resolved" selected', false)
            ->assertSee('value="hide_content" selected', false);
        $this->assertSame('pending', $report->fresh()->status);
        $this->assertSame('approved', $project->fresh()->status);
        $this->assertDatabaseCount('admin_actions', 0);
        $this->from($detailsUrl)->post($postUrl, [
            'report_id' => $report->id, 'status' => 'resolved', 'action' => 'hide_content', 'admin_note' => 'Confirmed irrelevant listing content.',
        ])->assertRedirect($detailsUrl)->assertSessionHasNoErrors();
        $this->assertSame('hidden', $project->fresh()->status);
        $this->assertSame('resolved', $report->fresh()->status);
        $entry = AdminAction::where('action', 'report.resolved')->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame($project->id, $entry->metadata['affected_id']);
        $this->assertSame('hidden', $entry->after_values['project_status']);
        $this->assertStringNotContainsString($reporter->name, $project->owner->activities()->latest()->first()->body);
    }

    private function report(User $reporter, string $type, int $subjectId, array $overrides = []): Report
    {
        return Report::create([
            'user_id' => $reporter->id, 'reportable_type' => $type, 'reportable_id' => $subjectId,
            'reason' => 'spam', 'details' => 'PRIVATE_REPORT_DETAILS', 'admin_note' => 'PRIVATE_ADMIN_NOTE',
            'status' => 'pending', ...$overrides,
        ]);
    }
}
