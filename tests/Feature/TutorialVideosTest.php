<?php

namespace Tests\Feature;

use App\Models\Tutorial;
use App\Models\User;
use Database\Seeders\TutorialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TutorialVideosTest extends TestCase
{
    use RefreshDatabase;

    private const VIDEO_ID = 'Z8J1MpTMTN8';

    public function test_the_initial_library_contains_ten_distinct_credited_youtube_tutorials(): void
    {
        $this->seed(TutorialSeeder::class);

        $tutorials = Tutorial::all();
        $this->assertCount(10, $tutorials);
        $this->assertCount(10, $tutorials->pluck('youtube_video_id')->unique());
        foreach ($tutorials as $tutorial) {
            $this->assertTrue($tutorial->is_published);
            $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{11}\z/', $tutorial->youtube_video_id);
            $this->assertNotEmpty(trim($tutorial->video_creator));
            $this->assertNotEmpty(trim($tutorial->excerpt));
            $this->assertSame('https://www.youtube.com/watch?v='.$tutorial->youtube_video_id, $tutorial->youtube_url);
        }

        $this->get(route('tutorials.index'))->assertOk()
            ->assertViewHas('tutorials', fn ($items) => $items->total() === 10 && $items->count() === 10);
    }

    public function test_reseeding_preserves_editorial_changes_bookmarks_and_existing_articles(): void
    {
        $this->seed(TutorialSeeder::class);
        $student = User::factory()->create();
        $edited = Tutorial::where('slug', 'develop-a-research-question')->sole();
        $edited->update(['title' => 'Our edited research tutorial', 'video_creator' => 'Updated creator credit', 'is_published' => false]);
        $student->bookmarks()->attach($edited);
        $legacy = Tutorial::factory()->create([
            'slug' => 'from-idea-to-research-problem',
            'title' => 'An existing written guide',
            'youtube_video_id' => null,
            'video_creator' => null,
        ]);
        $custom = Tutorial::factory()->create(['title' => 'An administrator curated video']);

        $this->seed(TutorialSeeder::class);

        $this->assertDatabaseCount('tutorials', 12);
        $this->assertSame('Our edited research tutorial', $edited->fresh()->title);
        $this->assertSame('Updated creator credit', $edited->fresh()->video_creator);
        $this->assertFalse($edited->fresh()->is_published);
        $this->assertDatabaseHas('tutorial_user', ['user_id' => $student->id, 'tutorial_id' => $edited->id]);
        $this->assertModelExists($legacy);
        $this->assertModelExists($custom);
        $this->get(route('tutorials.show', $legacy))->assertOk()->assertSee($legacy->title);
    }

    public function test_public_library_and_home_only_list_published_videos_and_support_creator_and_category_filters(): void
    {
        $visible = Tutorial::factory()->create([
            'title' => 'Plan a focused research question',
            'category' => 'Research questions',
            'video_creator' => 'Focused Research Academy',
        ]);
        $unpublished = Tutorial::factory()->create(['title' => 'A private tutorial draft', 'is_published' => false, 'category' => 'Draft topic']);
        $legacy = Tutorial::factory()->create(['title' => 'A retained written article', 'youtube_video_id' => null, 'category' => 'Archive topic']);

        $this->get(route('tutorials.index'))->assertOk()
            ->assertViewHas('tutorials', fn ($items) => $items->count() === 1 && $items->first()->is($visible))
            ->assertViewHas('categories', fn ($items) => $items->contains('Research questions') && ! $items->contains('Draft topic') && ! $items->contains('Archive topic'))
            ->assertSee($visible->title)->assertDontSee($unpublished->title)->assertDontSee($legacy->title);
        $this->get(route('tutorials.index', ['q' => 'Focused Research Academy', 'category' => 'Research questions']))
            ->assertOk()->assertViewHas('tutorials', fn ($items) => $items->count() === 1 && $items->first()->is($visible));
        $this->get(route('tutorials.index', ['q' => 'no matching creator']))
            ->assertOk()->assertViewHas('tutorials', fn ($items) => $items->isEmpty());
        $this->get(route('home'))->assertOk()
            ->assertViewHas('tutorials', fn ($items) => $items->count() === 1 && $items->first()->is($visible))
            ->assertViewHas('stats', fn ($stats) => $stats['tutorials'] === 1)
            ->assertDontSee($unpublished->title)->assertDontSee($legacy->title);
    }

    public function test_video_detail_uses_the_credited_privacy_embed_and_links_to_the_original_video(): void
    {
        $tutorial = Tutorial::factory()->create([
            'title' => 'A careful thesis methodology tutorial',
            'youtube_video_id' => self::VIDEO_ID,
            'video_creator' => 'Research & Writing Academy',
            'body' => '',
        ]);

        $this->get(route('tutorials.show', $tutorial))->assertOk()
            ->assertSee($tutorial->title)->assertSee($tutorial->video_creator)
            ->assertSee('data-video-embed="https://www.youtube-nocookie.com/embed/'.self::VIDEO_ID.'?rel=0"', false)
            ->assertSee('href="https://www.youtube.com/watch?v='.self::VIDEO_ID.'"', false)
            ->assertDontSee('autoplay=1', false);
    }

    public function test_private_videos_and_saved_filters_do_not_leak_between_accounts(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $saved = Tutorial::factory()->create(['title' => 'My saved methodology video']);
        $othersSaved = Tutorial::factory()->create(['title' => 'Someone elses saved video']);
        $unpublished = Tutorial::factory()->create(['title' => 'An unpublished saved video', 'is_published' => false]);
        $owner->bookmarks()->attach([$saved->id, $unpublished->id]);
        $other->bookmarks()->attach($othersSaved);

        $this->get(route('tutorials.show', $unpublished))->assertNotFound();
        $this->actingAs($owner)->get(route('tutorials.index', ['saved' => 1]))->assertOk()
            ->assertViewHas('tutorials', fn ($items) => $items->count() === 1 && $items->first()->is($saved))
            ->assertDontSee($othersSaved->title)->assertDontSee($unpublished->title);
        $this->get(route('account.dashboard', ['tab' => 'bookmarks']))->assertOk()
            ->assertSee($saved->title)->assertDontSee($othersSaved->title)->assertDontSee($unpublished->title);
        $this->post(route('account.bookmarks.toggle', $unpublished))->assertNotFound();
    }

    public function test_admin_can_normalize_youtube_share_watch_embed_and_shorts_links_without_requiring_written_notes(): void
    {
        $admin = User::factory()->admin()->create();
        $links = [
            'https://youtu.be/'.self::VIDEO_ID.'?si=sharing-token',
            'https://www.youtube.com/watch?v='.self::VIDEO_ID.'&t=45s',
            'https://m.youtube.com/watch?v='.self::VIDEO_ID,
            'https://www.youtube.com/embed/'.self::VIDEO_ID,
            'https://www.youtube.com/shorts/'.self::VIDEO_ID,
        ];

        $this->actingAs($admin);
        foreach ($links as $index => $url) {
            $this->post(route('admin.tutorials.store'), $this->videoData([
                'title' => 'Thesis video '.($index + 1), 'youtube_url' => $url,
            ]))->assertRedirect()->assertSessionHasNoErrors();
            $tutorial = Tutorial::where('title', 'Thesis video '.($index + 1))->sole();
            $this->assertSame(self::VIDEO_ID, $tutorial->youtube_video_id);
            $this->assertSame('Thesis Education Channel', $tutorial->video_creator);
            $this->assertSame('', $tutorial->body);
            $this->assertSame($admin->id, $tutorial->user_id);
            $this->assertSame('https://www.youtube.com/watch?v='.self::VIDEO_ID, $tutorial->youtube_url);
        }
    }

    public function test_admin_cannot_store_arbitrary_embed_hosts_schemes_malformed_ids_or_missing_creator_credits(): void
    {
        $admin = User::factory()->admin()->create();
        $invalid = [
            'http://www.youtube.com/watch?v='.self::VIDEO_ID,
            'javascript:alert(1)',
            'https://evil.example/embed/'.self::VIDEO_ID,
            'https://www.youtube.com.evil.example/watch?v='.self::VIDEO_ID,
            'https://www.youtube.com@evil.example/watch?v='.self::VIDEO_ID,
            'https://evil.example@www.youtube.com/watch?v='.self::VIDEO_ID,
            'https://youtu.be/'.self::VIDEO_ID.'/extra',
            'https://www.youtube.com/watch?v=too-short',
            'https://www.youtube.com/watch?v='.self::VIDEO_ID.'extra',
            'https://www.youtube.com/watch?v[]=anything',
            'https://www.youtube.com/watch?v=%22%3E%3Cscript%3E',
            'https://www.youtube.com/playlist?list=PLExample',
            '<iframe src="https://www.youtube.com/embed/'.self::VIDEO_ID.'"></iframe>',
        ];

        $this->actingAs($admin);
        foreach ($invalid as $url) {
            $this->postJson(route('admin.tutorials.store'), $this->videoData(['youtube_url' => $url]))
                ->assertUnprocessable()->assertJsonValidationErrors('youtube_url');
        }
        $this->postJson(route('admin.tutorials.store'), $this->videoData(['video_creator' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors('video_creator');
        $this->assertDatabaseCount('tutorials', 0);
    }

    public function test_students_cannot_create_replace_or_delete_curated_tutorial_videos(): void
    {
        $student = User::factory()->create();
        $tutorial = Tutorial::factory()->create(['youtube_video_id' => self::VIDEO_ID]);
        $originalTitle = $tutorial->title;

        $this->actingAs($student)->post(route('admin.tutorials.store'), $this->videoData())->assertForbidden();
        $this->put(route('admin.tutorials.update', $tutorial), $this->videoData(['title' => 'Unauthorized replacement']))->assertForbidden();
        $this->delete(route('admin.tutorials.destroy', $tutorial))->assertForbidden();
        $this->assertSame($originalTitle, $tutorial->fresh()->title);
        $this->assertSame(self::VIDEO_ID, $tutorial->fresh()->youtube_video_id);
        $this->assertDatabaseCount('tutorials', 1);
    }

    private function videoData(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Write a thesis with a clear plan',
            'category' => 'Research writing',
            'excerpt' => 'A video tutorial explaining how to plan the structure of a thesis.',
            'youtube_url' => 'https://www.youtube.com/watch?v='.self::VIDEO_ID,
            'video_creator' => 'Thesis Education Channel',
            'is_published' => true,
            'featured' => false,
        ], $overrides);
    }
}
