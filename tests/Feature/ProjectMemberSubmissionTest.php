<?php

namespace Tests\Feature;

use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectMemberSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_multipart_json_submission_accepts_registered_members_regardless_of_email_case_and_pasted_separators(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['email' => 'Owner@example.test']);
        $first = User::factory()->create(['email' => 'First.Member@example.test']);
        $second = User::factory()->create(['email' => 'second.member@example.test']);

        $response = $this->actingAs($owner)->post(route('projects.store'), $this->payload([
            'member_emails' => "  FIRST.MEMBER@EXAMPLE.TEST ; ;\r\n second.member@example.test,\n first.member@example.test, OWNER@EXAMPLE.TEST ",
            'document' => $this->pdfUpload(),
        ]), ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertCreated()->assertJsonStructure(['redirect', 'message'])->assertSessionHasNoErrors();

        $project = Project::sole();
        $response->assertJsonPath('redirect', route('account.projects.show', $project))
            ->assertJsonPath('message', 'Your thesis is ready for review and listed in community projects.')
            ->assertSessionHas('success', 'Your thesis is ready for review and listed in community projects.');
        $this->assertSame('approved', $project->status);
        $this->assertTrue($project->is_public);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $project->members()->pluck('users.id')->all());
        $this->assertSame(1, $first->activities()->count());
        $this->assertSame(1, $second->activities()->count());
        $this->assertSame(0, $owner->activities()->count());
        $document = $project->documents()->sole();
        Storage::disk('local')->assertExists($document->path);
        $this->get(route('projects.index'))->assertOk()->assertSee($project->title);
        $this->actingAs($first)->get(route('documents.download', $document))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('documents.download', $document))->assertForbidden();
    }

    public function test_json_submission_without_group_members_succeeds_and_flashes_success_for_the_following_navigation_only(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $response = $this->actingAs($owner)->postJson(route('projects.store'), $this->payload([
            'document' => $this->pdfUpload(),
        ]))->assertCreated();

        $project = Project::sole();
        $message = 'Your thesis is ready for review and listed in community projects.';
        $response->assertJsonPath('redirect', route('account.projects.show', $project))->assertJsonPath('message', $message);
        $this->assertCount(0, $project->members);
        $this->get(route('account.projects.show', $project))->assertOk()->assertSee($message);
        $this->get(route('account.projects.show', $project))->assertOk()->assertDontSee($message);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_a_blank_group_field_is_optional_and_an_admin_can_include_their_own_email(): void
    {
        Storage::fake('local');
        $owner = User::factory()->admin()->create(['email' => 'Admin.Owner@example.test']);
        $this->actingAs($owner)->postJson(route('projects.store'), $this->payload([
            'member_emails' => "  , ;\r\n ADMIN.OWNER@EXAMPLE.TEST ; ",
            'document' => $this->pdfUpload(), 'save_draft' => true,
        ]))->assertCreated()->assertJsonPath('message', 'Your thesis draft has been saved.');

        $project = Project::sole();
        $this->assertSame($owner->id, $project->user_id);
        $this->assertSame('draft', $project->status);
        $this->assertCount(0, $project->members);
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_invalid_or_unavailable_members_are_identified_and_neither_the_project_nor_pdf_is_saved(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $registered = User::factory()->create(['email' => 'active@example.test']);
        $evaluator = User::factory()->evaluator()->create(['email' => 'evaluator@example.test']);
        $suspended = User::factory()->create(['email' => 'suspended@example.test', 'is_suspended' => true]);

        $response = $this->actingAs($owner)->postJson(route('projects.store'), $this->payload([
            'member_emails' => 'active@example.test; unknown@example.test; not-an-email; evaluator@example.test; suspended@example.test',
            'document' => $this->pdfUpload(),
        ]))->assertUnprocessable()->assertJsonValidationErrors('member_emails');

        $errors = $response->json('errors.member_emails');
        $this->assertCount(4, $errors);
        foreach (['unknown@example.test', 'not-an-email', 'evaluator@example.test', 'suspended@example.test'] as $email) {
            $this->assertStringContainsString($email, implode(' ', $errors));
        }
        $this->assertStringContainsString('Correct or remove', implode(' ', $errors));
        $response->assertJsonMissingValidationErrors('document');
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('document_versions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        foreach ([$registered, $evaluator, $suspended] as $user) {
            $this->assertSame(0, $user->activities()->count());
        }
    }

    public function test_group_emails_allow_whitespace_only_entries_but_still_limit_distinct_members_to_fifteen(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $payload = $this->payload([
            'member_emails' => array_map(fn ($number) => 'member'.$number.'@example.test', range(1, 16)),
            'document' => $this->pdfUpload(),
        ]);
        $this->actingAs($owner)->postJson(route('projects.store'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('member_emails')
            ->assertJsonPath('errors.member_emails.0', 'Enter up to 15 registered student email addresses.');
        $this->assertDatabaseCount('projects', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $member = User::factory()->create(['email' => 'member@example.test']);
        $payload['member_emails'] = [...array_fill(0, 20, ' MEMBER@EXAMPLE.TEST '), '', '   '];
        $payload['document'] = $this->pdfUpload();
        $this->postJson(route('projects.store'), $payload)->assertCreated();
        $this->assertSame([$member->id], Project::sole()->members()->pluck('users.id')->all());
    }

    public function test_json_metadata_updates_return_a_redirect_without_replacing_the_existing_pdf(): void
    {
        Storage::fake('local');
        $project = Project::factory()->approved()->create(['is_public' => false]);
        $document = DocumentVersion::factory()->create(['project_id' => $project->id]);
        Storage::disk('local')->put($document->path, $this->pdf());
        $originalDocument = $document->fresh()->getAttributes();

        $response = $this->actingAs($project->owner)->putJson(route('projects.update', $project), $this->payload([
            'title' => 'Updated campus parking project', 'is_public' => false, 'member_emails' => '',
        ]))->assertOk()->assertJsonPath('redirect', route('account.projects.show', $project))
            ->assertJsonPath('message', 'Your thesis is ready for review. Your project details remain private.')
            ->assertSessionHas('success', 'Your thesis is ready for review. Your project details remain private.');

        $this->assertFalse($response->isRedirect());
        $this->assertSame('Updated campus parking project', $project->fresh()->title);
        $this->assertSame($originalDocument, $project->documents()->sole()->getAttributes());
        Storage::disk('local')->assertExists($document->path);
        $this->get(route('documents.download', $document))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('documents.download', $document))->assertForbidden();
    }

    public function test_existing_members_can_be_retained_after_suspension_but_an_owner_cannot_remove_them(): void
    {
        $project = Project::factory()->approved()->create();
        $member = User::factory()->create(['email' => 'Existing.Member@example.test', 'is_suspended' => true]);
        $project->members()->attach($member);
        $this->actingAs($project->owner)->putJson(route('projects.update', $project), $this->payload([
            'member_emails' => ' EXISTING.MEMBER@EXAMPLE.TEST ',
        ]))->assertOk()->assertJsonPath('redirect', route('account.projects.show', $project));

        $this->assertSame([$member->id], $project->members()->pluck('users.id')->all());
        $this->putJson(route('projects.update', $project), $this->payload(['member_emails' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors('member_emails');
        $this->assertSame([$member->id], $project->members()->pluck('users.id')->all());
    }

    public function test_an_unknown_member_on_update_keeps_existing_project_details_and_group_access(): void
    {
        $project = Project::factory()->approved()->create();
        $member = User::factory()->create();
        $project->members()->attach($member);
        $original = $project->fresh()->getAttributes();
        $this->actingAs($project->owner)->putJson(route('projects.update', $project), $this->payload([
            'title' => 'An update that must not be applied',
            'member_emails' => $member->email.'; unavailable@example.test',
        ]))->assertUnprocessable()->assertJsonValidationErrors('member_emails');

        $this->assertSame($original, $project->fresh()->getAttributes());
        $this->assertSame([$member->id], $project->members()->pluck('users.id')->all());
        $this->assertSame(0, $member->activities()->count());
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Automated campus parking management',
            'summary' => 'A thesis studying license plate recognition and real-time parking availability on a university campus.',
            'school' => 'Example University', 'course' => 'BS Information Technology',
            'category' => 'Web', 'feedback_needed' => 'Please review our methodology and system architecture.',
            'is_public' => true,
        ], $overrides);
    }

    private function pdfUpload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('parking-thesis.pdf', $this->pdf());
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    }
}
