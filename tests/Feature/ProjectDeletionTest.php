<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\DocumentVersion;
use App\Models\EvaluatorEvidence;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\Rating;
use App\Models\Report;
use App\Models\ReviewRequest;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Support\BuildsEvaluatorVerification;
use Tests\TestCase;

class ProjectDeletionTest extends TestCase
{
    use RefreshDatabase, BuildsEvaluatorVerification;

    public function test_project_deletion_is_owner_only_and_suspended_owners_cannot_delete(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $project = Project::factory()->approved()->create(['user_id' => $owner->id]);
        $document = $this->document($project, 'v1.pdf');
        $member = User::factory()->create();
        $project->members()->attach($member);
        $review = ReviewRequest::factory()->accepted()->create(['project_id' => $project->id, 'document_version_id' => $document->id]);
        $this->delete(route('projects.destroy', $project), ['delete_confirmation' => 'DELETE'])->assertRedirect(route('login'));
        foreach ([$member, $review->evaluator, User::factory()->admin()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)->delete(route('projects.destroy', $project), ['delete_confirmation' => 'DELETE'])->assertForbidden();
            $this->assertDatabaseHas('projects', ['id' => $project->id]);
            Storage::disk('local')->assertExists($document->path);
        }
        $owner->update(['is_suspended' => true]);
        $this->actingAs($owner)->delete(route('projects.destroy', $project), ['delete_confirmation' => 'DELETE'])->assertRedirect(route('login'));
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        Storage::disk('local')->assertExists($document->path);
    }

    public function test_deletion_requires_exact_explicit_confirmation_and_viewing_never_deletes(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $document = $this->document($project, 'v1.pdf');
        $this->actingAs($project->owner)->get(route('account.projects.show', $project))->assertOk();
        foreach ([[], ['delete_confirmation' => 'delete'], ['delete_confirmation' => 'DELETE THIS']] as $data) {
            $this->deleteJson(route('projects.destroy', $project), $data)->assertUnprocessable()->assertJsonValidationErrors('delete_confirmation');
            $this->assertDatabaseHas('projects', ['id' => $project->id]);
            Storage::disk('local')->assertExists($document->path);
        }
    }

    public function test_owner_deletion_cascades_only_the_target_graph_and_removes_exact_private_pdfs(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $evaluator = User::factory()->evaluator()->create();
        $project = Project::factory()->approved()->create(['user_id' => $owner->id]);
        $project->members()->attach($member);
        $first = $this->document($project, 'v1.pdf');
        $second = $this->document($project, 'v2.pdf');
        $review = ReviewRequest::factory()->accepted()->create(['project_id' => $project->id, 'document_version_id' => $first->id, 'evaluator_id' => $evaluator->id]);
        $completed = ReviewRequest::factory()->completed()->create(['project_id' => $project->id, 'document_version_id' => $second->id, 'evaluator_id' => $evaluator->id]);
        $feedback = Feedback::factory()->create(['review_request_id' => $review->id]);
        $rating = Rating::factory()->create(['review_request_id' => $completed->id]);
        $report = Report::create(['user_id' => $member->id, 'reportable_type' => 'project', 'reportable_id' => $project->id,
            'reason' => 'spam', 'details' => 'Preserve this report as moderation history.', 'status' => 'under_review']);
        $otherProject = Project::factory()->approved()->create();
        $keep = $this->document($otherProject, 'keep.pdf');
        $keepReview = ReviewRequest::factory()->completed()->create(['project_id' => $otherProject->id, 'document_version_id' => $keep->id, 'evaluator_id' => $evaluator->id]);
        $keepFeedback = Feedback::factory()->create(['review_request_id' => $keepReview->id]);
        $keepRating = Rating::factory()->create(['review_request_id' => $keepReview->id]);
        $untracked = 'theses/'.$project->id.'/untracked-keep.pdf';
        Storage::disk('local')->put($untracked, 'Untracked sibling file');
        Storage::disk('public')->put($first->path, 'Unrelated public disk content');
        $verification = $this->createVerification($evaluator, ['evidence_type' => 'document']);
        $proofPath = 'evaluator-evidence/'.$evaluator->id.'/'.$verification->id.'/proof.pdf';
        Storage::disk('local')->put($proofPath, 'Private professional proof');
        $proof = EvaluatorEvidence::create(['evaluator_verification_id' => $verification->id, 'path' => $proofPath,
            'original_name' => 'proof.pdf', 'mime_type' => 'application/pdf', 'size' => 26]);

        $this->actingAs($owner)->delete(route('projects.destroy', $project), ['delete_confirmation' => 'DELETE'])
            ->assertSessionHasNoErrors()->assertRedirect(route('account.projects'));
        foreach (['projects' => $project->id, 'document_versions' => $first->id, 'review_requests' => $review->id,
            'feedback' => $feedback->id, 'ratings' => $rating->id] as $table => $id) {
            $this->assertDatabaseMissing($table, ['id' => $id]);
        }
        $this->assertDatabaseMissing('document_versions', ['id' => $second->id]);
        $this->assertDatabaseMissing('review_requests', ['id' => $completed->id]);
        $this->assertDatabaseMissing('project_user', ['project_id' => $project->id]);
        $this->assertDatabaseHas('reports', ['id' => $report->id, 'reportable_id' => $project->id, 'status' => 'under_review']);
        foreach (['projects' => $otherProject->id, 'document_versions' => $keep->id, 'review_requests' => $keepReview->id,
            'feedback' => $keepFeedback->id, 'ratings' => $keepRating->id, 'evaluator_evidence' => $proof->id] as $table => $id) {
            $this->assertDatabaseHas($table, ['id' => $id]);
        }
        Storage::disk('local')->assertMissing($first->path);
        Storage::disk('local')->assertMissing($second->path);
        Storage::disk('local')->assertExists($keep->path);
        Storage::disk('local')->assertExists($untracked);
        Storage::disk('local')->assertExists($proofPath);
        Storage::disk('public')->assertExists($first->path);
        $this->assertSame(1, $member->activities()->where('title', 'Thesis project removed')->count());
        $this->assertSame(1, $evaluator->activities()->where('title', 'Thesis project removed')->count());
        $this->get(route('projects.show', $project))->assertNotFound();
        $this->get(route('projects.edit', $project))->assertNotFound();
        $this->get(route('documents.download', $first))->assertNotFound();
        $this->get(route('reviews.show', $review))->assertNotFound();
    }

    public function test_cleanup_preserves_paths_outside_the_project_and_files_still_referenced_by_another_project(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $shared = $this->document($project, 'shared-keep.pdf');
        DocumentVersion::factory()->create(['project_id' => $other->id, 'path' => $shared->path]);
        $proofPath = 'evaluator-evidence/unrelated-private-proof.pdf';
        Storage::disk('local')->put($proofPath, 'Unrelated proof bytes');
        DocumentVersion::factory()->create(['project_id' => $project->id, 'path' => $proofPath]);

        $this->actingAs($project->owner)->delete(route('projects.destroy', $project), ['delete_confirmation' => 'DELETE'])
            ->assertRedirect(route('account.projects'))->assertSessionHas('error');
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('projects', ['id' => $other->id]);
        $this->assertDatabaseHas('document_versions', ['project_id' => $other->id, 'path' => $shared->path]);
        Storage::disk('local')->assertExists($shared->path);
        Storage::disk('local')->assertExists($proofPath);
    }

    public function test_storage_cleanup_failure_is_reported_without_claiming_the_deleted_project_was_restored(): void
    {
        $disk = Storage::fake('local');
        $project = Project::factory()->create();
        $document = $this->document($project, 'v1.pdf');
        $failingDisk = Mockery::mock(FilesystemAdapter::class);
        $failingDisk->shouldReceive('exists')->with($document->path)->andReturnTrue();
        $failingDisk->shouldReceive('delete')->with($document->path)->once()->andReturnFalse();
        Storage::shouldReceive('disk')->with('local')->andReturn($failingDisk);

        $this->actingAs($project->owner)->delete(route('projects.destroy', $project), ['delete_confirmation' => 'DELETE'])
            ->assertRedirect(route('account.projects'))->assertSessionHas('error');
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('document_versions', ['id' => $document->id]);
        $this->assertTrue($disk->exists($document->path));
    }

    public function test_database_failure_rolls_back_deletion_and_skips_private_file_cleanup(): void
    {
        Storage::fake('local');
        $project = Project::factory()->create();
        $member = User::factory()->create();
        $project->members()->attach($member);
        $document = $this->document($project, 'v1.pdf');
        $event = 'eloquent.creating: '.Activity::class;
        Event::listen($event, static fn () => throw new RuntimeException('Simulated notification write failure'));
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($project->owner)->delete(route('projects.destroy', $project), ['delete_confirmation' => 'DELETE']);
            $this->fail('The simulated database write failure should abort the deletion transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated notification write failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('document_versions', ['id' => $document->id]);
        $this->assertDatabaseHas('project_user', ['project_id' => $project->id, 'user_id' => $member->id]);
        Storage::disk('local')->assertExists($document->path);
    }

    private function document(Project $project, string $filename): DocumentVersion
    {
        $document = DocumentVersion::factory()->create(['project_id' => $project->id, 'path' => 'theses/'.$project->id.'/'.$filename]);
        Storage::disk('local')->put($document->path, 'Private thesis document bytes');

        return $document;
    }
}
