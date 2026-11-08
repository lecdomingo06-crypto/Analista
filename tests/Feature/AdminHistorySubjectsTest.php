<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\Tutorial;
use App\Models\User;
use App\Support\AdminAudit;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminHistorySubjectsTest extends TestCase
{
    use RefreshDatabase;

    private string $ownedViews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->ownedViews = storage_path('framework/testing/admin-history-subjects-'.bin2hex(random_bytes(8)));
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

    public function test_current_target_names_are_minimal_escaped_and_never_written_back_into_audit_records(): void
    {
        $admin = User::factory()->admin()->create();
        $evaluator = User::factory()->evaluator()->create(['name' => 'Mina Evaluator', 'email' => 'PRIVATE_EVALUATOR@example.com']);
        $user = User::factory()->create(['name' => 'Cedar Member', 'email' => 'PRIVATE_MEMBER@example.com']);
        $project = Project::factory()->approved()->create(['title' => 'Orchard thesis', 'summary' => 'PRIVATE_THESIS_CONTENT']);
        $relatedProject = Project::factory()->approved()->create(['title' => 'Related thesis']);
        $tutorial = $this->tutorial();
        $invitation = Invitation::create([
            'email' => 'PRIVATE_INVITEE@example.com', 'token_hash' => hash('sha256', 'PRIVATE_INVITATION_TOKEN'),
            'invited_by' => $admin->id, 'expires_at' => now()->addDays(7),
        ]);
        AdminAudit::record($admin, 'evaluator.approved', 'evaluator', $evaluator->id, [], ['evaluator_status' => 'approved'], null, ['verification_id' => 123]);
        AdminAudit::record($admin, 'user.suspended', 'user', $user->id, ['is_suspended' => false], ['is_suspended' => true], null,
            ['affected_type' => 'project', 'affected_id' => $relatedProject->id]);
        AdminAudit::record($admin, 'project.hidden', 'project', $project->id, ['status' => 'approved'], ['status' => 'hidden']);
        AdminAudit::record($admin, 'tutorial.updated', 'tutorial', $tutorial->id, ['is_published' => false], ['is_published' => true]);
        AdminAudit::record($admin, 'invitation.created', 'invitation', $invitation->id, [], ['active' => true]);
        $before = AdminAction::orderBy('id')->get()->toJson();

        $response = $this->actingAs($admin)->get($this->historyUrl())->assertOk()
            ->assertSee('Mina Evaluator')->assertSee('Cedar Member')->assertSee('Orchard thesis')->assertSee('Research guide');
        $targets = $response->viewData('historyTargets');
        foreach ([['evaluator', $evaluator->id, 'name'], ['user', $user->id, 'name'], ['project', $project->id, 'title'],
            ['project', $relatedProject->id, 'title'], ['tutorial', $tutorial->id, 'title']] as [$type, $id, $column]) {
            $this->assertEqualsCanonicalizing(['id', $column], array_keys($targets[$type][$id]->getAttributes()));
        }
        foreach ([$evaluator->email, $user->email, $invitation->email, 'PRIVATE_THESIS_CONTENT', 'PRIVATE_TUTORIAL_BODY',
            'PRIVATE_INVITATION_TOKEN', $invitation->token_hash, 'Action #', 'Application #', 'Evaluator #'.$evaluator->id] as $private) {
            $response->assertDontSee($private);
        }
        $renamed = '<script>alert("person")</script> & Mina';
        $evaluator->update(['name' => $renamed]);
        $project->update(['title' => 'Renamed thesis']);
        $this->get($this->historyUrl())->assertOk()->assertSee(e($renamed), false)->assertDontSee($renamed, false)
            ->assertSee('Renamed thesis')->assertDontSee('Orchard thesis');
        $this->assertSame($before, AdminAction::orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('activities', 0);
    }

    public function test_target_search_is_type_guarded_bound_and_composes_with_action_filters(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Oak Administrator']);
        $evaluator = User::factory()->evaluator()->create(['name' => 'Mina 100%_Proof', 'email' => 'not-searched@example.com']);
        $project = Project::factory()->approved()->create(['id' => $evaluator->id, 'title' => 'Different thesis', 'summary' => 'PRIVATE_THESIS_SUMMARY']);
        $tutorial = $this->tutorial(['title' => 'Cedar guide']);
        $expected = AdminAudit::record($admin, 'evaluator.approved', 'evaluator', $evaluator->id, [], ['evaluator_status' => 'approved']);
        $projectEntry = AdminAudit::record($admin, 'project.hidden', 'project', $project->id, ['status' => 'approved'], ['status' => 'hidden']);
        $tutorialEntry = AdminAudit::record($admin, 'tutorial.created', 'tutorial', $tutorial->id, [], ['is_published' => true]);
        $this->actingAs($admin);
        foreach (['Mina', '%_', '100%_Proof'] as $q) {
            $this->get($this->historyUrl(['q' => $q]))->assertOk()
                ->assertViewHas('history', fn ($history) => $history->modelKeys() === [$expected->id]);
            $this->get($this->historyUrl(['q' => $q, 'action' => 'project.hidden']))->assertOk()
                ->assertViewHas('history', fn ($history) => $history->total() === 0);
        }
        foreach ([['Different thesis', $projectEntry->id], ['Cedar guide', $tutorialEntry->id]] as [$q, $id]) {
            $this->get($this->historyUrl(['q' => $q]))->assertOk()
                ->assertViewHas('history', fn ($history) => $history->modelKeys() === [$id]);
        }
        foreach (['not-searched@example.com', 'PRIVATE_THESIS_SUMMARY', 'PRIVATE_TUTORIAL_BODY', "' OR 1=1 --"] as $q) {
            $this->get($this->historyUrl(['q' => $q]))->assertOk()
                ->assertViewHas('history', fn ($history) => $history->total() === 0);
        }
        $this->assertDatabaseCount('admin_actions', 3);
    }

    public function test_target_loading_is_page_scoped_and_deleted_records_have_safe_fallbacks(): void
    {
        $admin = User::factory()->admin()->create();
        foreach (range(1, 21) as $index) {
            $user = User::factory()->create(['name' => 'Pagination member '.$index]);
            AdminAudit::record($admin, 'user.suspended', 'user', $user->id, ['is_suspended' => false], ['is_suspended' => true]);
        }
        $this->actingAs($admin);
        $response = $this->get($this->historyUrl(['q' => 'Pagination member', 'action' => 'user.suspended']))->assertOk();
        $this->assertSame(21, $response->viewData('history')->total());
        $this->assertCount(20, $response->viewData('historyTargets')['user']);
        $this->assertEqualsCanonicalizing($response->viewData('history')->pluck('target_id')->all(), $response->viewData('historyTargets')['user']->keys()->all());
        $next = $response->viewData('history')->nextPageUrl();
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame('Pagination member', $query['q']);
        $this->assertSame('user.suspended', $query['action']);
        $this->assertSame('2', $query['history_page']);
        $this->get($next)->assertOk()->assertViewHas('historyTargets', fn ($targets) => $targets['user']->count() === 1);
        $user->delete();
        $this->get($this->historyUrl())->assertOk()->assertSee('Account unavailable')->assertDontSee('Pagination member 21');
        $this->get(route('admin.index', ['section' => 'users']))->assertOk()
            ->assertViewHas('historyTargets', fn ($targets) => collect($targets)->every(fn ($records) => $records->isEmpty()));
        $this->assertDatabaseCount('admin_actions', 21);
    }

    private function historyUrl(array $query = []): string
    {
        return route('admin.index', ['section' => 'history', ...$query]);
    }

    private function tutorial(array $overrides = []): Tutorial
    {
        return Tutorial::create([
            'title' => 'Research guide', 'slug' => 'guide-'.bin2hex(random_bytes(5)), 'category' => 'Research writing',
            'excerpt' => 'A useful research guide.', 'body' => 'PRIVATE_TUTORIAL_BODY', 'is_published' => true,
            ...$overrides,
        ]);
    }
}
