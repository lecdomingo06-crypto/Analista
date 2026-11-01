<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function photo(string $name = 'portrait.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII='
        ));
    }

    public function test_all_roles_can_save_their_name_and_photo_without_changing_other_users(): void
    {
        $other = User::factory()->create(['name' => 'Another member']);
        foreach (['student', 'evaluator', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->from(route('account.profile'))->put(route('account.profile.update'), [
                'name' => '  Updated '.$role.' name  ', 'city' => ' Manila ', 'profile_photo' => $this->photo(),
            ])->assertSessionHasNoErrors()->assertRedirect(route('account.profile'));

            $user->refresh();
            $this->assertSame('Updated '.$role.' name', $user->name);
            $this->assertSame('Manila', $user->city);
            $this->assertSame($role, $user->role);
            $this->assertMatchesRegularExpression('/\Aprofile-photos\/'.$user->id.'\/[A-Za-z0-9]{40}\.png\z/', $user->profile_photo_path);
            Storage::disk('local')->assertExists($user->profile_photo_path);
            $this->assertSame(route('profile-photos.show', ['user' => $user, 'filename' => basename($user->profile_photo_path)]), $user->profile_photo_url);
            $this->assertArrayNotHasKey('profile_photo_path', $user->toArray());
        }
        $this->assertSame('Another member', $other->fresh()->name);
        $this->assertNull($other->fresh()->profile_photo_path);
    }

    public function test_replacing_and_removing_a_photo_deletes_only_the_previous_owned_file(): void
    {
        $user = User::factory()->evaluator()->create();
        $status = $user->evaluator_status;
        $otherPath = 'verification-evidence/keep-private.pdf';
        Storage::disk('local')->put($otherPath, 'Unrelated private file');
        $this->actingAs($user)->put(route('account.profile.update'), ['name' => $user->name, 'profile_photo' => $this->photo()])->assertSessionHasNoErrors();
        $first = $user->fresh()->profile_photo_path;
        $this->put(route('account.profile.update'), ['name' => $user->name, 'profile_photo' => $this->photo('replacement.png')])->assertSessionHasNoErrors();
        $second = $user->fresh()->profile_photo_path;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
        $this->get(route('profile-photos.show', ['user' => $user, 'filename' => basename($first)]))->assertNotFound();
        $this->put(route('account.profile.update'), ['name' => $user->name, 'remove_profile_photo' => true])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->profile_photo_path);
        $this->assertNull($user->fresh()->profile_photo_url);
        $this->assertSame($status, $user->fresh()->evaluator_status);
        Storage::disk('local')->assertMissing($second);
        Storage::disk('local')->assertExists($otherPath);
    }

    public function test_jpeg_and_webp_photos_use_their_detected_raster_content_type(): void
    {
        $images = [
            'jpg' => '/9j/4AAQSkZJRgABAQEAYABgAAD//gA+Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBkZWZhdWx0IHF1YWxpdHkK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSwwMTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy/8AAEQgAAgACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A+f6KKKAP/9k=',
            'webp' => 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoCAAIAAUAmJaQAA3AA/v02aAA=',
        ];
        $user = User::factory()->create();
        foreach ($images as $extension => $base64) {
            $this->actingAs($user)->put(route('account.profile.update'), [
                'name' => $user->name,
                'profile_photo' => UploadedFile::fake()->createWithContent('portrait.'.$extension, base64_decode($base64)),
            ])->assertSessionHasNoErrors();
            $mime = $extension === 'jpg' ? 'image/jpeg' : 'image/webp';
            $this->get($user->fresh()->profile_photo_url)->assertOk()->assertHeader('Content-Type', $mime);
        }
    }

    public function test_photo_responses_serve_only_the_current_raster_file_with_safe_headers(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->put(route('account.profile.update'), ['name' => $user->name, 'profile_photo' => $this->photo()])->assertSessionHasNoErrors();
        $user->refresh();
        $this->app['auth']->forgetGuards();
        $this->get($user->profile_photo_url)->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $this->get(route('profile-photos.show', ['user' => $user, 'filename' => str_repeat('a', 40).'.png']))->assertNotFound();
        $user->update(['is_suspended' => true]);
        $this->get($user->profile_photo_url)->assertNotFound();
    }

    public function test_crafted_stored_paths_never_expose_or_delete_private_documents(): void
    {
        $path = 'verification-evidence/private.png';
        Storage::disk('local')->put($path, $this->photo()->getContent());
        $user = User::factory()->create(['profile_photo_path' => $path]);
        $this->assertNull($user->profile_photo_url);
        $this->get(route('profile-photos.show', ['user' => $user, 'filename' => str_repeat('a', 40).'.png']))->assertNotFound();
        $this->actingAs($user)->put(route('account.profile.update'), ['name' => $user->name, 'remove_profile_photo' => true])->assertSessionHasNoErrors();
        Storage::disk('local')->assertExists($path);

        $other = User::factory()->create();
        $otherPath = 'profile-photos/'.$other->id.'/'.str_repeat('b', 40).'.png';
        Storage::disk('local')->put($otherPath, $this->photo()->getContent());
        $user->update(['profile_photo_path' => $otherPath]);
        $this->assertNull($user->fresh()->profile_photo_url);
        $this->put(route('account.profile.update'), ['name' => $user->name, 'remove_profile_photo' => true])->assertSessionHasNoErrors();
        Storage::disk('local')->assertExists($otherPath);
    }

    public function test_invalid_or_oversized_uploads_and_conflicting_controls_do_not_partially_save(): void
    {
        $user = User::factory()->create(['name' => 'Original name']);
        $badUploads = [
            UploadedFile::fake()->createWithContent('payload.png', '<?php echo "not a photo"; ?>'),
            UploadedFile::fake()->createWithContent('portrait.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>'),
            UploadedFile::fake()->create('large.png', 2049, 'image/png'),
            UploadedFile::fake()->createWithContent('too-wide.png', substr_replace($this->photo()->getContent(), pack('N', 4097), 16, 4)),
        ];
        foreach ($badUploads as $photo) {
            $this->actingAs($user)->put(route('account.profile.update'), ['name' => 'Should not save', 'profile_photo' => $photo])
                ->assertSessionHasErrors('profile_photo');
            $this->assertSame('Original name', $user->fresh()->name);
            $this->assertNull($user->fresh()->profile_photo_path);
            $this->assertEmpty(Storage::disk('local')->allFiles());
        }
        $this->put(route('account.profile.update'), [
            'name' => 'Should not save', 'profile_photo' => $this->photo(), 'remove_profile_photo' => true,
        ])->assertSessionHasErrors('profile_photo');
        $this->assertSame('Original name', $user->fresh()->name);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_failed_database_save_removes_new_upload_and_preserves_the_original(): void
    {
        $user = User::factory()->create();
        $oldPath = 'profile-photos/'.$user->id.'/'.str_repeat('c', 40).'.png';
        Storage::disk('local')->put($oldPath, $this->photo()->getContent());
        $user->update(['profile_photo_path' => $oldPath]);
        $this->actingAs($user)->withoutExceptionHandling();
        DB::shouldReceive('transaction')->once()->andThrow(new RuntimeException('Simulated database failure'));

        try {
            $this->put(route('account.profile.update'), ['name' => $user->name, 'profile_photo' => $this->photo()]);
            $this->fail('A failed database save should be propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated database failure', $exception->getMessage());
        }
        $this->assertSame([$oldPath], Storage::disk('local')->allFiles());
        $this->assertSame($oldPath, $user->fresh()->profile_photo_path);
    }

    public function test_password_changes_require_the_current_password_and_keep_the_current_session_signed_in(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123', 'remember_token' => 'previous-token']);
        $other = User::factory()->create(['password' => 'another-password-123']);
        $otherHash = $other->password;
        $this->actingAs($user)->put(route('account.profile.password'), [
            'current_password' => 'old-password-123', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456',
            'user_id' => $other->id, 'name' => 'Ignored password form name',
        ])->assertSessionHasNoErrors()->assertRedirect(route('account.profile').'#password');
        $user->refresh();
        $this->assertTrue(Hash::check('new-password-456', $user->password));
        $this->assertFalse(Hash::check('old-password-123', $user->password));
        $this->assertNotSame('previous-token', $user->remember_token);
        $this->assertSame(60, strlen($user->remember_token));
        $this->assertSame($otherHash, $other->fresh()->password);
        $this->assertNotSame('Ignored password form name', $user->name);
        $this->assertAuthenticatedAs($user);
        $this->get(route('account.profile'))->assertOk();
    }

    public function test_password_validation_does_not_save_or_flash_secrets(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $hash = $user->password;
        $cases = [
            ['wrong-password-123', 'new-password-456', 'new-password-456', 'current_password'],
            ['old-password-123', 'too-short', 'too-short', 'password'],
            ['old-password-123', 'new-password-456', 'does-not-match', 'password'],
            ['old-password-123', 'old-password-123', 'old-password-123', 'password'],
            ['old-password-123', str_repeat('😀', 20), str_repeat('😀', 20), 'password'],
        ];
        foreach ($cases as [$current, $new, $confirmation, $error]) {
            $this->actingAs($user)->from(route('account.profile'))->put(route('account.profile.password'), [
                'current_password' => $current, 'password' => $new, 'password_confirmation' => $confirmation,
            ])->assertSessionHasErrors($error)->assertRedirect(route('account.profile'));
            $old = session()->getOldInput();
            foreach (['current_password', 'password', 'password_confirmation'] as $secret) {
                $this->assertArrayNotHasKey($secret, $old);
            }
            $this->assertSame($hash, $user->fresh()->password);
        }
    }

    public function test_basic_profile_cannot_bypass_password_or_photo_path_controls_and_guests_cannot_write(): void
    {
        $this->put(route('account.profile.update'), ['name' => 'A guest'])->assertRedirect(route('login'));
        $this->put(route('account.profile.password'), [])->assertRedirect(route('login'));
        $user = User::factory()->create(['password' => 'old-password-123']);
        foreach (['password' => 'new-password-456', 'profile_photo_path' => 'verification-evidence/private.png', 'remember_token' => 'crafted'] as $field => $value) {
            $this->actingAs($user)->put(route('account.profile.update'), ['name' => 'Should not save', $field => $value])
                ->assertSessionHasErrors($field);
            $this->assertNotSame('Should not save', $user->fresh()->name);
            $this->assertTrue(Hash::check('old-password-123', $user->fresh()->password));
            $this->assertNull($user->fresh()->profile_photo_path);
        }
    }
}
