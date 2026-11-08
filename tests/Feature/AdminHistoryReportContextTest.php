<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Support\AdminAudit;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminHistoryReportContextTest extends TestCase
{
    use RefreshDatabase;

    private string $ownedViews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->ownedViews = storage_path('framework/testing/admin-history-context-'.bin2hex(random_bytes(8)));
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

    public function test_history_resolves_current_report_subjects_and_reporters_without_copying_private_records_into_audit_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $reporter = User::factory()->create(['name' => 'Cedar Reporter', 'email' => 'private-reporter@example.com']);
        $project = Project::factory()->approved()->create(['title' => 'Cedar thesis', 'summary' => 'PRIVATE_PROJECT_SUMMARY']);
        $evaluator = User::factory()->evaluator()->create(['name' => 'Cedar Evaluator']);
        $projectReport = $this->report($reporter, 'project', $project->id);
        $userReport = $this->report($reporter, 'user', $evaluator->id);
        $this->record($admin, $projectReport);
        $this->record($admin, $userReport);
        $before = AdminAction::orderBy('id')->get()->toJson();
        $response = $this->actingAs($admin)->get($this->historyUrl())->assertOk()
            ->assertSee('Cedar thesis')->assertSee('Cedar Reporter')->assertSee('Cedar Evaluator')
            ->assertDontSee('PRIVATE_PROJECT_SUMMARY')->assertDontSee('PRIVATE_REPORT_DETAILS')
            ->assertDontSee('PRIVATE_REPORT_ADMIN_NOTE')->assertDontSee($reporter->email);
        foreach ($response->viewData('historyReports') as $report) {
            $this->assertEqualsCanonicalizing(['id', 'user_id', 'reportable_type', 'reportable_id', 'reason', 'created_at'], array_keys($report->getAttributes()));
            $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($report->reporter->getAttributes()));
        }
        $subjects = $response->viewData('historySubjects');
        $this->assertEqualsCanonicalizing(['id', 'title'], array_keys($subjects['project'][$project->id]->getAttributes()));
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($subjects['user'][$evaluator->id]->getAttributes()));
        $renamed = '<script>alert("project")</script> & revised thesis';
        $project->update(['title' => $renamed]);
        $reporter->update(['name' => 'Renamed Reporter']);
        $evaluator->update(['name' => 'Renamed Evaluator']);
        $this->get($this->historyUrl())->assertOk()->assertSee(e($renamed), false)->assertDontSee($renamed, false)
            ->assertSee('Renamed Reporter')->assertSee('Renamed Evaluator');
        $this->assertSame($before, AdminAction::orderBy('id')->get()->toJson());
        foreach (['Cedar Reporter', 'Cedar thesis', 'Renamed Reporter', 'PRIVATE_REPORT_DETAILS', $reporter->email] as $private) {
            $this->assertStringNotContainsString($private, AdminAction::all()->toJson());
        }
        $this->assertDatabaseCount('activities', 0);
    }

    public function test_history_context_search_is_type_guarded_and_cannot_bypass_the_action_filter(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Oak Administrator']);
        $reporter = User::factory()->create(['name' => 'Cedar Reporter']);
        $project = Project::factory()->approved()->create(['id' => 99, 'title' => 'Orchard thesis']);
        $report = $this->report($reporter, 'project', $project->id);
        $expected = $this->record($admin, $report);
        $collision = AdminAudit::record($admin, 'project.hidden', 'project', $report->id, ['status' => 'approved'], ['status' => 'hidden']);
        $this->actingAs($admin);
        foreach (['Orchard thesis', 'Cedar Reporter', 'spam'] as $q) {
            $this->get($this->historyUrl(['q' => $q]))->assertOk()
                ->assertViewHas('history', fn ($history) => $history->modelKeys() === [$expected->id]);
            $this->get($this->historyUrl(['q' => $q, 'action' => 'project.hidden']))->assertOk()
                ->assertViewHas('history', fn ($history) => $history->total() === 0);
        }
        $this->get($this->historyUrl(['q' => 'Oak Administrator', 'action' => 'project.hidden']))->assertOk()
            ->assertViewHas('history', fn ($history) => $history->modelKeys() === [$collision->id]);
        foreach (['PRIVATE_REPORT_DETAILS', 'PRIVATE_REPORT_ADMIN_NOTE', '%', '_', "' OR 1=1 --"] as $q) {
            $this->get($this->historyUrl(['q' => $q]))->assertOk()
                ->assertViewHas('history', fn ($history) => $history->total() === 0);
        }
        $response = $this->get($this->historyUrl())->assertOk();
        $row = $this->historyRow($response->getContent(), $collision->id);
        $this->assertStringContainsString('Project unavailable', $row->textContent);
        $this->assertStringNotContainsString($collision->targetLabel(), $row->textContent);
        $this->assertStringNotContainsString('Orchard thesis', $row->textContent);
        $this->assertStringNotContainsString('Cedar Reporter', $row->textContent);
        $this->assertSame(0, $row->getElementsByTagName('a')->length);
    }

    public function test_history_batches_only_current_page_report_context_and_preserves_search_pagination(): void
    {
        $admin = User::factory()->admin()->create();
        $reporter = User::factory()->create(['name' => 'Pagination Reporter']);
        $owner = User::factory()->create();
        foreach (range(1, 21) as $index) {
            $project = Project::factory()->approved()->create(['user_id' => $owner->id, 'title' => 'Pagination project '.$index]);
            $this->record($admin, $this->report($reporter, 'project', $project->id));
        }
        $this->actingAs($admin);
        $response = $this->get($this->historyUrl(['q' => 'Pagination Reporter', 'action' => 'report.resolved']))->assertOk();
        $this->assertSame(21, $response->viewData('history')->total());
        $this->assertCount(20, $response->viewData('historyReports'));
        $this->assertCount(20, $response->viewData('historySubjects')['project']);
        $this->assertEqualsCanonicalizing($response->viewData('history')->pluck('target_id')->all(), $response->viewData('historyReports')->keys()->all());
        $next = $response->viewData('history')->nextPageUrl();
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame('history', $query['section']);
        $this->assertSame('Pagination Reporter', $query['q']);
        $this->assertSame('report.resolved', $query['action']);
        $this->assertSame('2', $query['history_page']);
        $page = $this->get($next)->assertOk();
        $this->assertCount(1, $page->viewData('historyReports'));
        $this->assertCount(1, $page->viewData('historySubjects')['project']);
        $this->get(route('admin.index', ['section' => 'users']))->assertOk()
            ->assertViewHas('historyReports', fn ($reports) => $reports->isEmpty())
            ->assertViewHas('historySubjects', fn ($subjects) => $subjects['project']->isEmpty() && $subjects['user']->isEmpty());
        $this->assertDatabaseCount('admin_actions', 21);
    }

    public function test_removed_reports_subjects_and_reporters_keep_safe_fallbacks_and_active_admin_authorization(): void
    {
        $admin = User::factory()->admin()->create();
        $reporter = User::factory()->create(['name' => 'Deleted Reporter']);
        $otherReporter = User::factory()->create(['name' => 'Surviving Reporter']);
        $project = Project::factory()->approved()->create(['title' => 'Deleted thesis']);
        $subjectRemoved = $this->report($otherReporter, 'project', $project->id);
        $reportRemoved = $this->report($reporter, 'project', $project->id);
        $subjectEntry = $this->record($admin, $subjectRemoved);
        $removedEntry = $this->record($admin, $reportRemoved);
        $project->delete();
        $reporter->delete();
        $url = $this->historyUrl();
        $this->get($url)->assertRedirect(route('login'));
        foreach ([User::factory()->create(), User::factory()->evaluator()->create()] as $viewer) {
            $this->actingAs($viewer)->get($url)->assertForbidden()->assertDontSee('Surviving Reporter');
        }
        $this->actingAs(User::factory()->admin()->create(['is_suspended' => true]))->get($url)->assertRedirect(route('login'));
        $before = AdminAction::orderBy('id')->get()->toJson();
        $response = $this->actingAs($admin)->get($url)->assertOk()->assertDontSee('Deleted thesis')->assertDontSee('Deleted Reporter');
        $this->assertSame([$subjectRemoved->id], $response->viewData('historyReports')->keys()->all());
        $this->assertTrue($response->viewData('historySubjects')['project']->isEmpty());
        $this->assertStringContainsString('Surviving Reporter', $this->historyRow($response->getContent(), $subjectEntry->id)->textContent);
        $deletedRow = $this->historyRow($response->getContent(), $removedEntry->id);
        $this->assertStringContainsString('Report unavailable', $deletedRow->textContent);
        $this->assertStringNotContainsString($removedEntry->targetLabel(), $deletedRow->textContent);
        $this->assertSame(0, $deletedRow->getElementsByTagName('a')->length);
        $this->assertSame($before, AdminAction::orderBy('id')->get()->toJson());
    }

    private function historyUrl(array $query = []): string
    {
        return route('admin.index', ['section' => 'history', ...$query]);
    }

    private function historyRow(string $html, int $id): \DOMElement
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $rows = (new \DOMXPath($document))->query('//article[@data-action-id="'.$id.'"]');
        $this->assertCount(1, $rows);

        return $rows->item(0);
    }

    private function report(User $reporter, string $type, int $subjectId): Report
    {
        return Report::create([
            'user_id' => $reporter->id, 'reportable_type' => $type, 'reportable_id' => $subjectId,
            'reason' => 'spam', 'details' => 'PRIVATE_REPORT_DETAILS', 'admin_note' => 'PRIVATE_REPORT_ADMIN_NOTE', 'status' => 'pending',
        ]);
    }

    private function record(User $admin, Report $report): AdminAction
    {
        return AdminAudit::record($admin, 'report.resolved', 'report', $report->id,
            ['status' => 'pending'], ['status' => 'resolved'], 'A reviewed moderation reason.');
    }
}
