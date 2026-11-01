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

class AdminOverviewChartsTest extends TestCase
{
    use RefreshDatabase;

    private string $ownedViews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->ownedViews = storage_path('framework/testing/admin-charts-'.bin2hex(random_bytes(8)));
        (new Filesystem)->ensureDirectoryExists($this->ownedViews);
        config(['view.compiled' => $this->ownedViews, 'app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
        $this->app->forgetInstance('blade.compiler');
        $this->app['view.engine.resolver']->forget('blade');
        $this->actingAs(User::factory()->admin()->create());
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

    public function test_graph_counts_percentages_and_review_offsets_include_all_statuses_without_private_records(): void
    {
        $owner = User::factory()->create(['name' => 'PRIVATE_PROJECT_OWNER', 'email' => 'private-owner@example.com']);
        foreach (['draft', 'draft', 'approved', 'approved', 'approved', 'completed', 'hidden', 'archived_legacy'] as $status) {
            Project::factory()->create([
                'user_id' => $owner->id, 'title' => 'PRIVATE_PROJECT_TITLE', 'summary' => 'PRIVATE_THESIS_CONTENT',
                'status' => $status,
            ]);
        }
        $source = Project::where('status', 'approved')->firstOrFail();
        $document = DocumentVersion::factory()->create(['project_id' => $source->id]);
        $evaluator = User::factory()->evaluator()->create(['name' => 'PRIVATE_EVALUATOR_NAME']);
        foreach (['accepted', 'accepted', 'completed', 'pending', 'declined', 'withdrawn_legacy'] as $status) {
            ReviewRequest::factory()->create([
                'project_id' => $source->id, 'document_version_id' => $document->id, 'evaluator_id' => $evaluator->id,
                'status' => $status, 'message' => 'PRIVATE_REQUEST_MESSAGE', 'final_summary' => 'PRIVATE_FINAL_SUMMARY',
            ]);
        }
        $snapshot = $this->recordSnapshot();
        $response = $this->get(route('admin.index'))->assertOk();
        $charts = $response->viewData('overviewCharts');
        $projects = collect($charts['projects']['items'])->keyBy('status');
        $reviews = collect($charts['reviews']['items'])->keyBy('status');
        $this->assertSame(8, $charts['projects']['total']);
        $this->assertSame(6, $charts['reviews']['total']);
        $this->assertSame(['draft', 'approved', 'completed', 'hidden', 'archived_legacy'], $projects->keys()->all());
        $this->assertSame(['accepted', 'completed', 'pending', 'declined', 'withdrawn_legacy'], $reviews->keys()->all());
        $this->assertSame(2, $projects['draft']['count']);
        $this->assertSame('Saved drafts', $projects['draft']['label']);
        $this->assertSame(3, $projects['approved']['count']);
        $this->assertSame('Ready for review', $projects['approved']['label']);
        $this->assertSame(1, $projects['archived_legacy']['count']);
        $this->assertSame('Archived Legacy', $projects['archived_legacy']['label']);
        $this->assertSame('Ongoing', $reviews['accepted']['label']);
        foreach ($charts as $chart) {
            $offset = 0.0;
            foreach ($chart['items'] as $item) {
                $this->assertIsInt($item['count']);
                $this->assertIsFloat($item['percent']);
                $this->assertEqualsWithDelta($item['count'] / $chart['total'] * 100, $item['percent'], 0.000001);
                if (isset($item['offset'])) {
                    $this->assertEqualsWithDelta($offset, $item['offset'], 0.000001);
                }
                $offset += $item['percent'];
            }
            $this->assertEqualsWithDelta(100, $offset, 0.000001);
            $this->assertSame($chart['total'], array_sum(array_column($chart['items'], 'count')));
        }
        $this->assertSame(8, $response->viewData('stats')['projects']);
        $this->assertSame(2, $response->viewData('stats')['drafts']);
        $this->assertSame(2, $response->viewData('stats')['ongoing_reviews']);
        $this->assertSame(1, $response->viewData('stats')['completed_reviews']);
        foreach (['PRIVATE_PROJECT_OWNER', 'private-owner@example.com', 'PRIVATE_PROJECT_TITLE', 'PRIVATE_THESIS_CONTENT',
            'PRIVATE_EVALUATOR_NAME', 'PRIVATE_REQUEST_MESSAGE', 'PRIVATE_FINAL_SUMMARY'] as $private) {
            $response->assertDontSee($private);
            $this->assertStringNotContainsString($private, json_encode($charts));
        }
        $this->assertSame($snapshot, $this->recordSnapshot());
    }

    public function test_empty_and_single_status_graphs_have_finite_percentages_and_stable_required_categories(): void
    {
        $empty = $this->get(route('admin.index'))->assertOk()->viewData('overviewCharts');
        $this->assertSame(['draft', 'approved', 'completed'], array_column($empty['projects']['items'], 'status'));
        $this->assertSame(['accepted', 'completed', 'pending', 'declined'], array_column($empty['reviews']['items'], 'status'));
        foreach ($empty as $chart) {
            $this->assertSame(0, $chart['total']);
            foreach ($chart['items'] as $item) {
                $this->assertSame(0, $item['count']);
                $this->assertSame(0.0, $item['percent']);
                if (isset($item['offset'])) {
                    $this->assertSame(0.0, $item['offset']);
                }
            }
        }
        $project = Project::factory()->approved()->create();
        $document = DocumentVersion::factory()->create(['project_id' => $project->id]);
        $evaluator = User::factory()->evaluator()->create();
        foreach (range(1, 3) as $index) {
            ReviewRequest::factory()->accepted()->create([
                'project_id' => $project->id, 'document_version_id' => $document->id, 'evaluator_id' => $evaluator->id,
            ]);
        }
        $filled = $this->get(route('admin.index'))->assertOk()->viewData('overviewCharts');
        $this->assertSame(1, $filled['projects']['total']);
        $this->assertSame([0.0, 100.0, 0.0], array_column($filled['projects']['items'], 'percent'));
        $this->assertSame(3, $filled['reviews']['total']);
        $this->assertSame([100.0, 0.0, 0.0, 0.0], array_column($filled['reviews']['items'], 'percent'));
        $this->assertSame([0.0, 100.0, 100.0, 100.0], array_column($filled['reviews']['items'], 'offset'));
    }

    public function test_status_graphs_are_available_only_on_the_protected_admin_overview(): void
    {
        $this->get(route('admin.index', ['section' => 'users']))->assertOk()->assertViewHas('overviewCharts', []);
        $this->get(route('admin.index', ['section' => 'projects']))->assertOk()->assertViewHas('overviewCharts', []);
        $this->actingAs(User::factory()->create())->get(route('admin.index'))->assertForbidden();
        $this->actingAs(User::factory()->evaluator()->create())->get(route('admin.index'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create(['is_suspended' => true]))->get(route('admin.index'))
            ->assertRedirect(route('login'));
        $this->get(route('admin.index'))->assertRedirect(route('login'));
    }

    private function recordSnapshot(): array
    {
        $snapshot = [];
        foreach (['projects', 'review_requests', 'users', 'document_versions', 'activities', 'admin_actions', 'sensitive_file_accesses'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }
}
