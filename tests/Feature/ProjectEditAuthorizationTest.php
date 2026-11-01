<?php

namespace Tests\Feature;

use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectEditAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private string $ownedViews;

    private string $ownedDisk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $token = 'project-edit-authorization-'.bin2hex(random_bytes(8));
        $this->ownedViews = storage_path('framework/testing/'.$token);
        (new Filesystem)->ensureDirectoryExists($this->ownedViews);
        config(['view.compiled' => $this->ownedViews]);
        $this->app->forgetInstance('blade.compiler');
        $this->app['view.engine.resolver']->forget('blade');
        $this->ownedDisk = storage_path('framework/testing/disks/'.$token);
        Storage::set('local', Storage::fake($token));
    }

    protected function tearDown(): void
    {
        $paths = array_filter([$this->ownedViews ?? null, $this->ownedDisk ?? null]);
        $ownedRoot = realpath(storage_path('framework/testing'));
        try {
            parent::tearDown();
        } finally {
            foreach ($paths as $path) {
                $resolved = realpath($path);
                if ($ownedRoot !== false && $resolved !== false
                    && str_starts_with(strtolower($resolved), strtolower(rtrim($ownedRoot, '\\/').DIRECTORY_SEPARATOR))) {
                    (new Filesystem)->deleteDirectory($resolved);
                }
            }
        }
    }

    public function test_an_unrelated_admin_can_inspect_private_documents_and_reviews_without_project_edit_controls(): void
    {
        [$project, $document] = $this->projectWithDocument();
        $project->update(['status' => 'completed']);
        $review = ReviewRequest::factory()->completed()->create([
            'project_id' => $project->id,
            'document_version_id' => $document->id,
            'final_summary' => 'Private recommendations explaining how to improve the thesis methodology and database design.',
        ]);
        $review->update([
            'output_path' => 'review-outputs/'.$review->id.'/'.str_repeat('a', 40).'.pdf',
            'output_original_name' => 'private-annotated-review.pdf',
            'output_size' => strlen($this->pdf()),
        ]);
        Storage::disk('local')->put($review->output_path, $this->pdf());
        $this->actingAs(User::factory()->admin()->create());

        foreach (['projects.show', 'account.projects.show'] as $route) {
            $response = $this->get(route($route, $project))->assertOk()
                ->assertSee($document->original_name)
                ->assertDontSee($review->final_summary)->assertDontSee($review->output_original_name)
                ->assertDontSee(route('reviews.output', $review))
                ->assertSee('href="'.route('documents.download', $document).'"', false)
                ->assertSee('href="'.route('reviews.show', $review).'"', false)
                ->assertDontSee('href="'.route('projects.edit', $project).'"', false)
                ->assertDontSee('action="'.route('projects.update', $project).'"', false)
                ->assertDontSee('action="'.route('projects.upload', $project).'"', false)
                ->assertDontSee('Upload a revision');

            $html = new \DOMDocument;
            @$html->loadHTML($response->getContent());
            $xpath = new \DOMXPath($html);
            foreach (['is_public', 'pdf_access', 'review_mode', 'reviewer_limit'] as $name) {
                $this->assertSame(0, $xpath->query('//input[@name="'.$name.'"] | //select[@name="'.$name.'"]')->length);
            }
        }
        $this->get(route('documents.download', $document))->assertOk()->assertDownload($document->original_name);
        $this->get(route('reviews.show', $review))->assertOk()
            ->assertSee($review->final_summary)->assertSee($review->output_original_name)
            ->assertSee(route('reviews.output', $review));
        $this->get(route('reviews.output', $review))->assertOk()->assertDownload('private-annotated-review.pdf');
    }

    public function test_an_unrelated_admin_cannot_edit_settings_membership_or_upload_a_version_and_nothing_is_changed(): void
    {
        [$project, $document] = $this->projectWithDocument();
        $member = User::factory()->create();
        $project->members()->attach($member);
        $review = ReviewRequest::factory()->accepted()->create([
            'project_id' => $project->id, 'document_version_id' => $document->id,
        ]);
        $before = $this->snapshot($project);
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('projects.edit', $project))->assertForbidden();
        $this->putJson(route('projects.update', $project), $this->payload([
            'title' => 'Unauthorized administrator rewrite',
            'is_public' => true,
            'pdf_access' => Project::PDF_ACCESS_APPROVED_EVALUATORS,
            'review_mode' => Project::REVIEW_MODE_VOLUNTEER,
            'reviewer_limit' => 5,
            'member_emails' => '',
        ]))->assertForbidden();
        $this->post(route('projects.upload', $project), ['document' => $this->pdfUpload()])->assertForbidden();

        $this->assertSame($before, $this->snapshot($project));
        $this->assertSame($document->id, $review->fresh()->document_version_id);
        $this->assertTrue($project->members()->whereKey($member->id)->exists());
    }

    public function test_the_owner_and_current_group_members_can_edit_settings_and_add_versions_without_rebinding_reviews(): void
    {
        [$project, $document] = $this->projectWithDocument();
        $member = User::factory()->create();
        $project->members()->attach($member);
        $review = ReviewRequest::factory()->accepted()->create([
            'project_id' => $project->id, 'document_version_id' => $document->id,
        ]);

        foreach ([$project->owner, $member] as $index => $editor) {
            $this->assertEditorCanUpdateAndUpload($project, $editor, $index + 2);
            $this->assertSame($document->id, $review->fresh()->document_version_id);
            $this->assertTrue($project->members()->whereKey($member->id)->exists());
            Storage::disk('local')->assertExists($document->path);
        }
    }

    public function test_an_admin_can_still_edit_a_project_they_own(): void
    {
        $admin = User::factory()->admin()->create();
        [$project] = $this->projectWithDocument($admin);

        $this->assertEditorCanUpdateAndUpload($project, $admin, 2);
        $this->assertSame($admin->id, $project->fresh()->user_id);
    }

    public function test_former_members_and_suspended_owners_members_or_admin_owners_cannot_edit_or_upload(): void
    {
        [$project] = $this->projectWithDocument();
        $formerMember = User::factory()->create();
        $project->members()->attach($formerMember);
        $project->members()->detach($formerMember);
        $before = $this->snapshot($project);
        $this->actingAs($formerMember)->get(route('projects.edit', $project))->assertForbidden();
        $this->putJson(route('projects.update', $project), $this->payload())->assertForbidden();
        $this->post(route('projects.upload', $project), ['document' => $this->pdfUpload()])->assertForbidden();
        $this->assertSame($before, $this->snapshot($project));

        $member = User::factory()->create();
        $project->members()->attach($member);
        $admin = User::factory()->admin()->create();
        [$adminProject] = $this->projectWithDocument($admin);
        foreach ([[$project, $project->owner], [$project, $member], [$adminProject, $admin]] as [$target, $actor]) {
            $actor->update(['is_suspended' => true]);
            $before = $this->snapshot($target);
            $this->actingAs($actor)->get(route('projects.edit', $target))->assertRedirect(route('login'));
            $this->actingAs($actor)->put(route('projects.update', $target), $this->payload())->assertRedirect(route('login'));
            $this->actingAs($actor)->post(route('projects.upload', $target), ['document' => $this->pdfUpload()])
                ->assertRedirect(route('login'));
            $this->assertSame($before, $this->snapshot($target));
        }
    }

    private function assertEditorCanUpdateAndUpload(Project $project, User $editor, int $expectedVersion): void
    {
        $this->actingAs($editor)->get(route('projects.edit', $project))->assertOk();
        $this->get(route('account.projects.show', $project))->assertOk()
            ->assertSee('href="'.route('projects.edit', $project).'"', false)
            ->assertSee('action="'.route('projects.upload', $project).'"', false);
        $title = 'Authorized thesis update by '.$editor->id;
        $this->put(route('projects.update', $project), $this->payload([
            'title' => $title, 'is_public' => true,
            'pdf_access' => Project::PDF_ACCESS_APPROVED_EVALUATORS,
            'review_mode' => Project::REVIEW_MODE_VOLUNTEER, 'reviewer_limit' => 3,
        ]))->assertSessionHasNoErrors()->assertRedirect(route('account.projects.show', $project));
        $project->refresh();
        $this->assertSame($title, $project->title);
        $this->assertTrue($project->is_public);
        $this->assertSame(Project::PDF_ACCESS_APPROVED_EVALUATORS, $project->pdf_access);
        $this->assertSame(Project::REVIEW_MODE_VOLUNTEER, $project->review_mode);
        $this->assertSame(3, $project->reviewer_limit);
        $this->post(route('projects.upload', $project), ['document' => $this->pdfUpload()])
            ->assertSessionHasNoErrors()->assertRedirect(route('account.projects.show', $project));
        $latest = $project->documents()->orderByDesc('version')->firstOrFail();
        $this->assertSame($expectedVersion, $latest->version);
        $this->assertSame($editor->id, $latest->user_id);
        $this->assertSame($expectedVersion, $project->documents()->count());
        Storage::disk('local')->assertExists($latest->path);
    }

    private function projectWithDocument(?User $owner = null): array
    {
        $project = Project::factory()->approved()->create([
            'user_id' => $owner?->id ?? User::factory()->create()->id,
            'is_public' => false,
            'pdf_access' => Project::PDF_ACCESS_ASSIGNED,
        ]);
        $document = DocumentVersion::factory()->create([
            'project_id' => $project->id, 'path' => 'theses/'.$project->id.'/original-thesis.pdf',
            'original_name' => 'original-student-thesis.pdf', 'size' => strlen($this->pdf()),
        ]);
        Storage::disk('local')->put($document->path, $this->pdf());

        return [$project, $document];
    }

    private function snapshot(Project $project): array
    {
        $files = Storage::disk('local')->allFiles();
        sort($files);

        return [
            'project' => $project->fresh()->getAttributes(),
            'members' => DB::table('project_user')->where('project_id', $project->id)->orderBy('user_id')
                ->get()->map(fn ($row) => (array) $row)->all(),
            'documents' => $project->documents()->orderBy('id')->get()->map->getAttributes()->all(),
            'reviews' => $project->reviewRequests()->orderBy('id')->get()->map->getAttributes()->all(),
            'activities' => DB::table('activities')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'files' => array_map(fn ($path) => [$path, hash('sha256', Storage::disk('local')->get($path))], $files),
        ];
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Campus service tracking thesis',
            'summary' => 'A thesis examining how students request campus services and track their progress through a shared system.',
            'school' => 'Example Community College', 'course' => 'BS Information Technology',
            'category' => 'Web', 'technologies' => 'Laravel, MySQL',
            'feedback_needed' => 'Please review the methodology and database design.',
        ], $overrides);
    }

    private function pdfUpload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('revision.pdf', $this->pdf());
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    }
}
