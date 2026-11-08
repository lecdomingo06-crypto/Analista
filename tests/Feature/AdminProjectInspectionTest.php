<?php

namespace Tests\Feature;

use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminProjectInspectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        config(['app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
        Storage::fake('local');
    }

    public function test_project_inspection_is_restricted_to_active_administrators(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $owner, 'status' => 'hidden', 'summary' => 'Private inspection content.']);
        $url = route('admin.projects.show', $project);

        $this->get($url)->assertRedirect(route('login'))->assertDontSee($project->summary);
        foreach ([$owner, User::factory()->evaluator()->create()] as $viewer) {
            $this->actingAs($viewer)->get($url)->assertForbidden()->assertDontSee($project->summary);
        }
        $suspended = User::factory()->admin()->create(['is_suspended' => true]);
        $this->actingAs($suspended)->get($url)->assertRedirect(route('login'))->assertDontSee($project->summary);
        $this->assertGuest();
        $this->actingAs(User::factory()->admin()->create())->get($url)->assertOk()->assertSee($project->summary);
    }

    public function test_an_admin_can_inspect_full_hidden_project_details_without_changing_any_records(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create(['name' => 'Private project owner']);
        $project = Project::factory()->create([
            'user_id' => $owner, 'title' => 'Hidden thesis inspection', 'status' => 'hidden', 'is_public' => false,
            'summary' => 'Complete project context stays on the inspection page.',
            'feedback_needed' => 'Please evaluate the private methodology and testing plan.',
            'moderation_note' => 'Remove the unrelated advertising before restoration.',
            'school' => 'Private inspection university', 'course' => 'Inspection degree',
        ]);
        $document = DocumentVersion::factory()->create(['project_id' => $project, 'original_name' => 'inspection-thesis.pdf']);
        Storage::disk('local')->put($document->path, "%PDF-1.4\n%%EOF");
        $before = $this->inspectionRecords();

        $response = $this->actingAs($admin)->get(route('admin.projects.show', $project))->assertOk();
        foreach ([$project->title, $owner->name, $project->summary, $project->feedback_needed, $project->moderation_note,
            $project->school, $project->course, $document->original_name] as $detail) {
            $response->assertSee($detail);
        }
        $response->assertSee('href="'.route('documents.download', $document).'"', false);
        $xpath = $this->xpath($response->getContent());
        $form = $xpath->query('//form[@data-project-moderation]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $form);
        $this->assertSame('POST', strtoupper($form->getAttribute('method')));
        $this->assertSame(route('admin.projects.moderate', $project), strtok($form->getAttribute('action'), '?'));
        $this->assertSame(1, $xpath->query('.//input[@name="_token" and string-length(@value) > 0]', $form)->length);
        $this->assertSame((string) $project->id, $xpath->query('.//input[@name="moderation_project_id"]', $form)->item(0)->getAttribute('value'));
        $note = $xpath->query('.//textarea[@name="reason"]', $form)->item(0);
        $this->assertTrue($note->hasAttribute('required'));
        $this->assertSame('2000', $note->getAttribute('maxlength'));
        foreach (['approve', 'needs_changes', 'reject', 'hide'] as $decision) {
            $this->assertSame(1, $xpath->query('.//button[@type="submit" and @name="decision" and @value="'.$decision.'"]', $form)->length);
        }
        $this->assertSame(1, $xpath->query('.//button[@value="hide" and @data-confirm]', $form)->length);
        $this->assertSame($before, $this->inspectionRecords());
    }

    public function test_collage_cards_show_titles_short_descriptions_capsules_and_inspection_links_with_filter_context(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create(['name' => 'Owner visible on the inspection page only']);
        $preview = str_repeat('Orchard field data. ', 5).'Field sensor reports';
        $project = Project::factory()->create([
            'user_id' => $owner, 'title' => 'Orchard control system', 'status' => 'hidden',
            'summary' => $preview.' and the full detailed methodology stay available on the inspection page.',
            'feedback_needed' => 'Feedback detail omitted from compact cards.',
            'moderation_note' => 'Previous decision omitted from compact cards.',
            'technologies' => ['Laravel', 'React', 'MySQL', 'Redis', 'Docker', 'PHP'],
        ]);
        $context = ['q' => 'Orchard', 'status' => 'hidden', 'projects_page' => 1];
        $response = $this->actingAs($admin)->get(route('admin.index', ['section' => 'projects', ...$context]))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $card = $xpath->query('//article[@id="project-'.$project->id.'"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $card);
        $this->assertSame(1, $xpath->query('.//h3[@id="project-title-'.$project->id.'"]', $card)->length);
        $this->assertStringContainsString($project->title, $card->textContent);
        $description = $xpath->query('.//p[contains(concat(" ", normalize-space(@class), " "), " admin-project-card-description ")]', $card)->item(0);
        $this->assertInstanceOf(DOMElement::class, $description);
        $this->assertSame($preview.'...', $description->textContent);
        foreach ([$owner->name, $project->summary, $project->feedback_needed, $project->moderation_note] as $excluded) {
            $this->assertStringNotContainsString($excluded, $card->textContent);
        }
        $this->assertSame(0, $xpath->query('.//form|.//textarea|.//details|.//button', $card)->length);
        $tags = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " admin-project-technologies ")]/span', $card);
        $this->assertSame(['Laravel', 'React', 'MySQL', '+3'], array_map(fn ($tag) => trim($tag->textContent), iterator_to_array($tags)));
        $links = $xpath->query('.//a', $card);
        $this->assertSame(2, $links->length);
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            $this->assertSame(parse_url(route('admin.projects.show', $project), PHP_URL_PATH), parse_url($href, PHP_URL_PATH));
            parse_str(parse_url($href, PHP_URL_QUERY) ?? '', $query);
            $this->assertEquals(['q' => 'Orchard', 'status' => 'hidden', 'projects_page' => '1'], $query);
            if (str_contains($link->textContent, 'Inspect project')) {
                $this->assertNull(parse_url($href, PHP_URL_FRAGMENT));
            } else {
                $this->assertStringContainsString('Moderate', $link->textContent);
                $this->assertSame('project-moderation', parse_url($href, PHP_URL_FRAGMENT));
            }
        }
    }

    public function test_inspection_back_link_keeps_only_validated_list_context(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->create();
        $context = ['q' => '  Orchard & field  ', 'status' => 'all', 'projects_page' => 3, 'redirect' => 'https://example.com'];
        $response = $this->actingAs($admin)->get(route('admin.projects.show', ['project' => $project, ...$context]))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $links = $xpath->query('//a[contains(normalize-space(.), "Back to projects")]');
        $this->assertGreaterThanOrEqual(1, $links->length);
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            $this->assertSame(parse_url(route('admin.index'), PHP_URL_PATH), parse_url($href, PHP_URL_PATH));
            parse_str(parse_url($href, PHP_URL_QUERY) ?? '', $query);
            $this->assertEquals(['section' => 'projects', 'q' => 'Orchard & field', 'status' => 'all', 'projects_page' => '3'], $query);
        }
    }

    public function test_inspection_rejects_invalid_or_unbounded_list_context(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $project = Project::factory()->create();
        foreach ([
            ['q' => str_repeat('a', 201)], ['q' => ['unexpected']],
            ['status' => 'suspended'], ['status' => ['hidden']],
            ['projects_page' => 0], ['projects_page' => 1000001], ['projects_page' => ['2']],
        ] as $context) {
            $this->getJson(route('admin.projects.show', ['project' => $project, ...$context]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('admin_actions', 0);
        $this->assertDatabaseCount('activities', 0);
    }

    public function test_approval_requires_a_document_and_restoration_can_bypass_the_required_note(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $pending = Project::factory()->create(['status' => 'pending']);
        $xpath = $this->xpath($this->get(route('admin.projects.show', $pending))->assertOk()->getContent());
        $approve = $xpath->query('//form[@data-project-moderation]//button[@name="decision" and @value="approve"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $approve);
        $this->assertTrue($approve->hasAttribute('disabled'));

        $hidden = Project::factory()->create(['status' => 'hidden']);
        DocumentVersion::factory()->create(['project_id' => $hidden]);
        $xpath = $this->xpath($this->get(route('admin.projects.show', $hidden))->assertOk()->getContent());
        $restore = $xpath->query('//form[@data-project-moderation]//button[@name="decision" and @value="approve"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $restore);
        $this->assertFalse($restore->hasAttribute('disabled'));
        $this->assertTrue($restore->hasAttribute('formnovalidate'));
        $this->assertStringContainsString('Restore project', $restore->textContent);
        $this->assertSame(1, $xpath->query('//*[@id="project-moderation"]')->length);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }

    private function inspectionRecords(): array
    {
        $records = [];
        foreach (['users', 'projects', 'document_versions', 'review_requests', 'activities', 'admin_actions', 'sensitive_file_accesses'] as $table) {
            $records[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $records;
    }
}
