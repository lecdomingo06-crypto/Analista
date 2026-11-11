<?php

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\Tutorial;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class EvaluatorTutorialsTest extends TestCase
{
    use DatabaseMigrations;

    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $disk = Storage::fake('tutorial-upload-'.bin2hex(random_bytes(8)));
        $this->diskRoot = $disk->path('');
        Storage::set('local', $disk);
    }

    protected function tearDown(): void
    {
        $root = realpath(storage_path('framework/testing/disks'));
        $disk = isset($this->diskRoot) ? realpath($this->diskRoot) : false;
        try {
            parent::tearDown();
        } finally {
            if ($root && $disk && str_starts_with(strtolower($disk), strtolower(rtrim($root, '\\/').DIRECTORY_SEPARATOR))) {
                (new Filesystem)->deleteDirectory($disk);
            }
        }
    }

    public function test_approved_evaluators_can_publish_a_link_and_manage_only_their_own_tutorials(): void
    {
        $author = User::factory()->evaluator()->create();
        $other = User::factory()->evaluator()->create();
        $this->actingAs($author)->get(route('account.tutorials.create'))->assertOk()->assertSee('Video source')->assertDontSee('name="featured"', false);
        $this->post(route('account.tutorials.store'), $this->payload(['user_id' => $other->id, 'slug' => 'untrusted-slug']))
            ->assertRedirect(route('account.tutorials.index'))->assertSessionHasNoErrors();
        $tutorial = Tutorial::sole();
        $this->assertSame($author->id, $tutorial->user_id);
        $this->assertNotSame('untrusted-slug', $tutorial->slug);
        $this->assertTrue($tutorial->is_published);
        $this->assertFalse($tutorial->featured);
        $this->assertSame('71-GucBaM8U', $tutorial->youtube_video_id);
        $this->get(route('account.tutorials.index'))->assertOk()->assertSee($tutorial->title)->assertSee('My tutorials');
        $this->get(route('tutorials.index'))->assertOk()->assertSee($tutorial->title)->assertSee('Create tutorial');
        $this->actingAs($other)->get(route('account.tutorials.index'))->assertOk()->assertDontSee($tutorial->title);
        $this->get(route('account.tutorials.edit', $tutorial))->assertForbidden();
        $this->put(route('account.tutorials.update', $tutorial), $this->payload(['title' => 'Unauthorized edit']))->assertForbidden();
        $this->delete(route('account.tutorials.destroy', $tutorial))->assertForbidden();
        $this->assertSame('Plan a clear thesis methodology', $tutorial->fresh()->title);
        $this->assertDatabaseCount('admin_actions', 0);
    }

    public function test_students_pending_rejected_suspended_evaluators_and_guests_cannot_publish(): void
    {
        $this->get(route('account.tutorials.create'))->assertRedirect(route('login'));
        $this->post(route('account.tutorials.store'), $this->payload())->assertRedirect(route('login'));
        foreach ([User::factory()->create(), User::factory()->pendingEvaluator()->create(), User::factory()->create(['role' => 'evaluator', 'evaluator_status' => 'rejected'])] as $user) {
            $this->actingAs($user)->get(route('account.tutorials.index'))->assertForbidden();
            $this->get(route('account.tutorials.create'))->assertForbidden();
            $this->post(route('account.tutorials.store'), $this->payload())->assertForbidden();
            $this->get(route('tutorials.index'))->assertOk()->assertDontSee('href="'.route('account.tutorials.create').'"', false);
        }
        $this->actingAs(User::factory()->evaluator()->create(['is_suspended' => true]))->post(route('account.tutorials.store'), $this->payload())->assertRedirect(route('login'));
        $this->assertDatabaseCount('tutorials', 0);
    }

    public function test_uploaded_mp4_and_webm_videos_appear_in_the_library_and_support_playback_and_byte_ranges(): void
    {
        $author = User::factory()->evaluator()->create();
        foreach (['mp4' => 'video/mp4', 'webm' => 'video/webm'] as $extension => $mime) {
            $this->actingAs($author)->post(route('account.tutorials.store'), $this->uploadPayload($extension))
                ->assertRedirect()->assertSessionHasNoErrors();
            $tutorial = Tutorial::latest('id')->firstOrFail();
            $this->assertTrue($tutorial->has_uploaded_video);
            $this->assertTrue($tutorial->is_video);
            $this->assertNull($tutorial->youtube_video_id);
            $this->assertNull($tutorial->youtube_url);
            $this->assertNull($tutorial->thumbnail_url);
            $this->assertSame($mime, $tutorial->video_mime_type);
            $this->assertArrayNotHasKey('video_path', $tutorial->toArray());
            Storage::disk('local')->assertExists($tutorial->safeVideoPath());
            $this->post(route('logout'));
            $this->get(route('tutorials.index'))->assertOk()->assertSee($tutorial->title);
            $this->get(route('home'))->assertOk()->assertSee($tutorial->title);
            $this->get(route('tutorials.show', $tutorial))->assertOk()->assertSee('<video controls', false)->assertSee(route('tutorials.video', $tutorial))->assertDontSee('data-video-embed', false)->assertDontSee($tutorial->video_path);
            $this->get(route('tutorials.video', $tutorial))->assertOk()->assertHeader('Content-Type', $mime)->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->withHeaders(['Range' => 'bytes=0-31'])->get(route('tutorials.video', $tutorial))->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-31/'.$tutorial->video_size);
            $this->flushHeaders();
            $student = User::factory()->create();
            $this->actingAs($student)->post(route('account.bookmarks.toggle', $tutorial))->assertRedirect();
            $this->get(route('tutorials.index', ['saved' => 1]))->assertOk()->assertSee($tutorial->title);
        }
    }

    public function test_draft_uploads_are_private_and_can_be_published_without_uploading_the_video_again(): void
    {
        $author = User::factory()->evaluator()->create();
        $this->actingAs($author)->post(route('account.tutorials.store'), $this->uploadPayload('webm', ['is_published' => false]))->assertSessionHasNoErrors();
        $tutorial = Tutorial::sole();$path = $tutorial->video_path;
        $this->get(route('tutorials.show', $tutorial))->assertOk()->assertSee('Draft preview.');
        $this->get(route('tutorials.video', $tutorial))->assertOk();
        foreach ([User::factory()->create(), User::factory()->evaluator()->create()] as $other) {
            $this->actingAs($other)->get(route('tutorials.show', $tutorial))->assertNotFound();
            $this->get(route('tutorials.video', $tutorial))->assertNotFound();
            $this->get(route('tutorials.index'))->assertDontSee($tutorial->title);
        }
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('tutorials.video', $tutorial))->assertOk();
        $this->post(route('logout'));
        $this->get(route('tutorials.video', $tutorial))->assertNotFound();
        $this->actingAs($author)->put(route('account.tutorials.update', $tutorial), $this->payload(['source_type' => 'upload', 'youtube_url' => null]))->assertSessionHasNoErrors();
        $this->assertTrue($tutorial->fresh()->is_published);
        $this->assertSame($path, $tutorial->fresh()->video_path);
        $this->post(route('logout'));
        $this->get(route('tutorials.video', $tutorial))->assertOk();
    }

    public function test_replacing_a_video_changing_to_a_link_and_deleting_remove_only_the_retired_files(): void
    {
        $author = User::factory()->evaluator()->create();
        $this->actingAs($author)->post(route('account.tutorials.store'), $this->uploadPayload('webm'))->assertSessionHasNoErrors();
        $tutorial = Tutorial::sole();$first = $tutorial->video_path;
        $this->post(route('account.tutorials.store'), $this->uploadPayload('mp4', ['title' => 'A separate tutorial']))->assertSessionHasNoErrors();
        $separate = Tutorial::latest('id')->firstOrFail();
        $this->put(route('account.tutorials.update', $tutorial), $this->uploadPayload('mp4'))->assertSessionHasNoErrors();
        $second = $tutorial->fresh()->video_path;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
        Storage::disk('local')->assertExists($separate->video_path);
        $this->put(route('account.tutorials.update', $tutorial), $this->payload())->assertSessionHasNoErrors();
        $this->assertNull($tutorial->fresh()->video_path);
        Storage::disk('local')->assertMissing($second);
        $this->delete(route('account.tutorials.destroy', $separate))->assertRedirect(route('account.tutorials.index'));
        Storage::disk('local')->assertMissing($separate->video_path);
        $this->assertDatabaseMissing('tutorials', ['id' => $separate->id]);
    }

    public function test_video_validation_rejects_invalid_types_sizes_sources_and_evaluator_feature_flags_without_storing_files(): void
    {
        $author = User::factory()->evaluator()->create();$this->actingAs($author);
        foreach ([
            [$this->payload(['source_type' => 'upload', 'youtube_url' => null]), 'video'],
            [$this->uploadPayload('webm', ['source_type' => 'youtube']), 'video'],
            [$this->uploadPayload('webm', ['source_type' => 'remote']), 'source_type'],
            [$this->payload(['youtube_url' => 'https://evil.example/video']), 'youtube_url'],
            [$this->uploadPayload('webm', ['video' => UploadedFile::fake()->createWithContent('tutorial.webm', '<?php echo 123;')]), 'video'],
            [$this->uploadPayload('webm', ['video' => UploadedFile::fake()->create('tutorial.webm', 30 * 1024 + 1, 'video/webm')]), 'video'],
            [$this->payload(['featured' => true]), 'featured'],
        ] as [$payload, $field]) {
            $this->postJson(route('account.tutorials.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('tutorials', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_admins_can_manage_an_evaluators_uploaded_video_and_keep_the_correct_author_and_audit_metadata(): void
    {
        $author = User::factory()->evaluator()->create();
        $this->actingAs($author)->post(route('account.tutorials.store'), $this->uploadPayload('mp4'))->assertSessionHasNoErrors();
        $tutorial = Tutorial::sole();$path = $tutorial->video_path;
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.index', ['section' => 'tutorials']))->assertOk()->assertSee($author->name)->assertSee('Uploaded video');
        $this->put(route('admin.tutorials.update', $tutorial), $this->payload(['source_type' => 'upload', 'youtube_url' => null, 'is_published' => false, 'featured' => true]))->assertSessionHasNoErrors();
        $this->assertSame($author->id, $tutorial->fresh()->user_id);
        $this->assertSame($path, $tutorial->fresh()->video_path);
        $this->assertTrue($tutorial->fresh()->featured);
        $this->assertFalse($tutorial->fresh()->is_published);
        $entry = AdminAction::where('action', 'tutorial.updated')->sole();
        $this->assertStringNotContainsString($path, $entry->toJson());
        $this->delete(route('admin.tutorials.destroy', $tutorial))->assertRedirect();
        $this->assertDatabaseMissing('tutorials', ['id' => $tutorial->id]);
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseHas('admin_actions', ['action' => 'tutorial.deleted', 'target_id' => $tutorial->id]);
    }

    public function test_a_database_or_audit_failure_rolls_back_the_tutorial_and_removes_the_new_upload(): void
    {
        $admin = User::factory()->admin()->create();
        $dispatcher = AdminAction::getEventDispatcher();
        AdminAction::setEventDispatcher(clone $dispatcher);
        AdminAction::creating(static function (): never { throw new RuntimeException('Simulated tutorial audit failure'); });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($admin)->post(route('admin.tutorials.store'), $this->uploadPayload('webm'));
            $this->fail('The simulated audit error should abort creation.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated tutorial audit failure', $error->getMessage());
            $this->assertDatabaseCount('tutorials', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        } finally {
            AdminAction::setEventDispatcher($dispatcher);
        }
    }

    public function test_video_delivery_never_reads_paths_outside_the_tutorial_video_directory(): void
    {
        Storage::disk('local')->put('unrelated-private-file.webm', file_get_contents(base_path('tests/Fixtures/tutorial-sample.webm')));
        $tutorial = Tutorial::factory()->create(['youtube_video_id' => null, 'video_path' => '../unrelated-private-file.webm']);
        $this->get(route('tutorials.video', $tutorial))->assertNotFound();
        Storage::disk('local')->assertExists('unrelated-private-file.webm');
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['title' => 'Plan a clear thesis methodology', 'category' => 'Methodology', 'excerpt' => 'Practical guidance for designing a defensible research method.', 'video_creator' => 'Presentation Evaluator', 'source_type' => 'youtube', 'youtube_url' => 'https://youtu.be/71-GucBaM8U?si=example', 'body' => '', 'is_published' => true], $overrides);
    }

    private function uploadPayload(string $extension, array $overrides = []): array
    {
        return $this->payload(array_replace(['title' => 'Uploaded '.$extension.' tutorial', 'source_type' => 'upload', 'youtube_url' => null,
            'video' => new UploadedFile(base_path('tests/Fixtures/tutorial-sample.'.$extension), 'tutorial.'.$extension, null, null, true)], $overrides));
    }
}
