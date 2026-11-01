<?php

namespace Tests\Feature;

use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectVisibilityContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_project_pages_hide_private_materials_for_guests_and_all_students_including_the_team(): void
    {
        [$project, $member, $document, $revision, $review] = $this->completedProject();
        foreach ([null, User::factory()->create(), $project->owner, $member] as $viewer) {
            if ($viewer) {
                $this->actingAs($viewer);
            }
            $response = $this->get(route('projects.show', $project))->assertOk()
                ->assertViewHas('canViewPrivate', false)->assertViewHas('isProjectWorkspace', false)
                ->assertViewHas('documents', fn ($items) => $items->isEmpty())
                ->assertViewHas('reviewRequests', fn ($items) => $items->isEmpty())
                ->assertViewHas('evaluators', fn ($items) => $items->isEmpty())
                ->assertSee($project->summary)->assertSee($project->feedback_needed)
                ->assertDontSee('Private documents')->assertDontSee('Review requests')
                ->assertDontSee('Private review summary')->assertDontSee('Upload a revision')
                ->assertDontSee($document->original_name)->assertDontSee($revision->original_name)
                ->assertDontSee($review->final_summary)->assertDontSee($review->output_original_name)
                ->assertDontSee(route('documents.download', $document))->assertDontSee(route('reviews.output', $review))
                ->assertDontSee('action="'.route('projects.upload', $project).'"', false);
            $this->assertFalse($response->viewData('project')->relationLoaded('members'));
            if ($viewer && in_array($viewer->id, [$project->user_id, $member->id], true)) {
                $response->assertViewHas('canManage', true)
                    ->assertSee('href="'.route('account.projects.show', $project).'"', false);
            }
        }
    }

    public function test_owner_and_members_can_manage_private_materials_while_admins_can_inspect_them(): void
    {
        [$project, $member, $document, $revision, $review] = $this->completedProject();
        $admin = User::factory()->admin()->create();
        foreach ([$project->owner, $member, $admin] as $viewer) {
            $response = $this->actingAs($viewer)->get(route('account.projects.show', $project))->assertOk()
                ->assertViewHas('canManage', true)->assertViewHas('canViewPrivate', true)
                ->assertViewHas('isProjectWorkspace', true)
                ->assertSee($document->original_name)->assertSee($revision->original_name)
                ->assertDontSee($review->final_summary)->assertDontSee($review->output_original_name)
                ->assertSee(route('documents.download', $document))->assertDontSee(route('reviews.output', $review))
                ->assertSee(route('reviews.show', $review));
            if ($viewer->is($admin)) {
                $response->assertViewHas('canEdit', false)
                    ->assertDontSee('action="'.route('projects.upload', $project).'"', false)
                    ->assertDontSee('href="'.route('projects.edit', $project).'"', false);
            } else {
                $response->assertViewHas('canEdit', true)
                    ->assertSee('action="'.route('projects.upload', $project).'"', false)
                    ->assertSee('Back to my projects');
            }
            $this->get(route('reviews.show', $review))->assertOk()
                ->assertSee($review->final_summary)->assertSee($review->output_original_name)
                ->assertSee(route('reviews.output', $review));
        }
        $this->actingAs($admin)->get(route('projects.show', $project))->assertOk()
            ->assertViewHas('isProjectWorkspace', false)->assertViewHas('canViewPrivate', true)
            ->assertDontSee($review->final_summary)->assertDontSee($review->output_original_name)
            ->assertDontSee(route('reviews.output', $review))->assertSee(route('reviews.show', $review));
    }

    public function test_workspace_urls_cannot_be_forged_and_pdf_download_authorization_is_unchanged(): void
    {
        Storage::fake('local');
        [$project, , $document, , $review] = $this->completedProject();
        Storage::disk('local')->put($document->path, $this->pdf());
        $this->get(route('account.projects.show', $project))->assertRedirect(route('login'));
        $this->get(route('documents.download', $document))->assertRedirect(route('login'));
        $outsider = User::factory()->create();
        foreach ([$outsider, $review->evaluator] as $viewer) {
            $this->actingAs($viewer)->get(route('account.projects.show', $project))->assertNotFound();
        }
        $this->actingAs($outsider)->get(route('projects.show', ['project' => $project, 'workspace' => 1, 'private' => 1]))
            ->assertOk()->assertViewHas('canViewPrivate', false)->assertDontSee($review->final_summary);
        $this->get(route('documents.download', $document))->assertForbidden();
        $this->actingAs($project->owner)->get(route('documents.download', $document))->assertOk()->assertDownload($document->original_name);
    }

    public function test_private_legacy_student_links_and_review_upload_flows_use_the_authorized_workspace(): void
    {
        Storage::fake('local');
        $project = Project::factory()->approved()->create();
        $document = DocumentVersion::factory()->create(['project_id' => $project->id]);
        $evaluator = User::factory()->evaluator()->create();
        $workspace = route('account.projects.show', ['project' => $project, 'evaluator' => $evaluator->id]);
        $this->actingAs($project->owner)->get(route('projects.show', ['project' => $project, 'evaluator' => $evaluator->id]))
            ->assertRedirect($workspace);
        $this->get($workspace)->assertOk()->assertViewHas('canViewPrivate', true)
            ->assertSee('action="'.route('reviews.store', $project).'"', false)
            ->assertSee('value="'.$evaluator->id.'" selected', false);
        $this->get(route('reviews.prepare', ['project_id' => $project->id, 'evaluator_id' => $evaluator->id]))
            ->assertRedirect($workspace.'#request-review');
        $this->post(route('projects.upload', $project), [
            'document' => UploadedFile::fake()->createWithContent('revision.pdf', $this->pdf()),
        ])->assertSessionHasNoErrors()->assertRedirect(route('account.projects.show', $project));
        $this->assertSame(2, $project->documents()->count());
        $this->assertSame(1, $document->fresh()->version);
        $this->actingAs(User::factory()->create())->get(route('projects.show', $project))->assertNotFound();
    }

    public function test_assigned_evaluators_see_only_their_own_completed_review_files_and_results(): void
    {
        Storage::fake('local');
        [$project, , $document, $revision, $review] = $this->completedProject();
        $otherReview = ReviewRequest::factory()->completed()->create([
            'project_id' => $project->id, 'document_version_id' => $revision->id,
            'final_summary' => 'Another evaluator private summary that must stay within their assigned review.',
            'output_original_name' => 'other-private-annotations.pdf',
        ]);
        $otherReview->update(['output_path' => 'review-outputs/'.$otherReview->id.'/'.str_repeat('b', 40).'.pdf']);
        $latest = DocumentVersion::factory()->create(['project_id' => $project->id, 'original_name' => 'unassigned-latest-revision.pdf']);
        foreach ([$document, $revision] as $pdf) {
            Storage::disk('local')->put($pdf->path, $this->pdf());
        }
        foreach ([[$review, $otherReview], [$otherReview, $review]] as [$owned, $foreign]) {
            $this->actingAs($owned->evaluator)->get(route('projects.show', $project))->assertOk()
                ->assertViewHas('canManage', false)->assertViewHas('canViewPrivate', true)
                ->assertViewHas('documents', fn ($items) => $items->modelKeys() === [$owned->document_version_id])
                ->assertViewHas('reviewRequests', fn ($items) => $items->modelKeys() === [$owned->id])
                ->assertSee($owned->document->original_name)->assertDontSee($owned->final_summary)
                ->assertDontSee($owned->output_original_name)->assertDontSee(route('reviews.output', $owned))
                ->assertSee(route('reviews.show', $owned))
                ->assertDontSee($foreign->document->original_name)->assertDontSee($foreign->final_summary)
                ->assertDontSee($latest->original_name)->assertDontSee('Upload a revision');
            $this->get(route('reviews.show', $owned))->assertOk()
                ->assertSee($owned->final_summary)->assertSee($owned->output_original_name)
                ->assertSee(route('reviews.output', $owned))->assertDontSee($foreign->final_summary);
            $this->get(route('documents.download', $owned->document))->assertOk();
            $this->get(route('documents.download', $foreign->document))->assertForbidden();
        }
        $pending = ReviewRequest::factory()->create(['project_id' => $project->id]);
        $declined = ReviewRequest::factory()->create(['project_id' => $project->id, 'status' => 'declined']);
        foreach ([$pending->evaluator, $declined->evaluator, User::factory()->evaluator()->create()] as $viewer) {
            $this->actingAs($viewer)->get(route('projects.show', $project))->assertOk()
                ->assertViewHas('canViewPrivate', false)->assertDontSee($review->final_summary)
                ->assertDontSee($otherReview->final_summary)->assertDontSee($document->original_name);
            $this->get(route('documents.download', $document))->assertForbidden();
        }
    }

    private function completedProject(): array
    {
        $project = Project::factory()->publicListing()->create(['status' => 'completed']);
        $member = User::factory()->create();
        $project->members()->attach($member);
        $document = DocumentVersion::factory()->create(['project_id' => $project->id, 'original_name' => 'private-original-thesis.pdf']);
        $revision = DocumentVersion::factory()->create(['project_id' => $project->id, 'original_name' => 'private-second-revision.pdf']);
        $review = ReviewRequest::factory()->completed()->create([
            'project_id' => $project->id, 'document_version_id' => $document->id,
            'final_summary' => 'Private thesis review findings for the team and the evaluator assigned to this document version.',
            'output_original_name' => 'private-evaluator-annotations.pdf',
        ]);
        $review->update(['output_path' => 'review-outputs/'.$review->id.'/'.str_repeat('a', 40).'.pdf']);

        return [$project, $member, $document, $revision, $review];
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    }
}
