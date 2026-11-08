<?php

namespace Tests\Feature;

use App\Models\DirectConversation;
use App\Models\User;
use Database\Seeders\AdditionalEvaluatorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class AdditionalEvaluatorSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_explicit_seeding_creates_three_regular_approved_accounts_with_unique_working_logins(): void
    {
        User::factory()->admin()->create();
        $existing = User::factory()->evaluator()->create(['name' => 'Existing volunteer']);
        $original = $existing->refresh()->getAttributes();
        $seeder = new AdditionalEvaluatorSeeder;
        $seeder->run();

        $credentials = $seeder->credentials();
        $this->assertCount(3, $credentials);
        $this->assertCount(3, array_unique(array_column($credentials, 'password')));
        $this->assertSame($original, $existing->fresh()->getAttributes());
        $this->assertDatabaseCount('evaluator_verifications', 3);
        $this->assertDatabaseCount('ratings', 0);
        $this->assertDatabaseCount('projects', 0);
        $this->get(route('evaluators.index'))->assertOk()
            ->assertSee('Adrian Mendoza')->assertSee('Camille Villareal')->assertSee('Nathaniel Reyes')
            ->assertViewHas('evaluators', fn ($evaluators) => $evaluators->total() === 4);

        foreach ($credentials as $credential) {
            $evaluator = User::where('email', $credential['email'])->sole();
            $this->assertTrue($evaluator->isEvaluator());
            $this->assertTrue($evaluator->accepting_requests);
            $this->assertNull($evaluator->email_verified_at);
            $this->assertSame(0, $evaluator->experience_years);
            $this->assertSame('Approved directly at the project owner\'s request.', $evaluator->moderation_note);
            $this->assertStringEndsWith('@analista.test', $evaluator->email);
            $this->assertStringNotContainsString('@evaluators.', $evaluator->email);
            $this->assertStringNotContainsString('Sample', $evaluator->headline);
            $this->assertStringNotContainsString('Sample', $evaluator->bio);
            $this->assertGreaterThanOrEqual(24, strlen($credential['password']));
            $this->assertNotSame($credential['password'], $evaluator->getRawOriginal('password'));
            $this->assertTrue(Auth::validate(['email' => $credential['email'], 'password' => $credential['password']]));
            $this->get(route('evaluators.show', $evaluator))->assertOk()->assertDontSee('Sample evaluator');
        }
    }

    public function test_legacy_conversion_preserves_ids_photos_messages_and_other_accounts(): void
    {
        User::factory()->admin()->create();
        $existing = User::factory()->create();
        $original = $existing->refresh()->getAttributes();
        $legacy = $this->legacyEvaluator('adrian.mendoza', 'Adrian Mendoza');
        $this->legacyEvaluator('camille.villareal', 'Camille Villareal');
        $this->legacyEvaluator('nathaniel.reyes', 'Nathaniel Reyes');
        $legacy->update(['profile_photo_path' => 'profile-photos/'.$legacy->id.'/portrait.jpg']);
        $photo = $legacy->profile_photo_path;
        $oldPassword = $legacy->getRawOriginal('password');
        $oldToken = $legacy->remember_token;
        $activity = $legacy->notifyActivity('Existing notification', 'Keep this notification.', '/account');
        $conversation = DirectConversation::create([
            'lower_user_id' => min($existing->id, $legacy->id),
            'higher_user_id' => max($existing->id, $legacy->id),
        ]);
        $message = $conversation->messages()->create([
            'sender_id' => $existing->id,
            'recipient_id' => $legacy->id,
            'body' => 'Existing discussion must survive conversion.',
        ]);

        $seeder = new AdditionalEvaluatorSeeder;
        $seeder->run();

        $converted = User::where('email', 'adrian.mendoza@analista.test')->sole();
        $this->assertSame($legacy->id, $converted->id);
        $this->assertSame($photo, $converted->profile_photo_path);
        $this->assertSame($legacy->created_at->toISOString(), $converted->created_at->toISOString());
        $this->assertNotSame($oldPassword, $converted->getRawOriginal('password'));
        $this->assertNotSame($oldToken, $converted->remember_token);
        $this->assertTrue($converted->isEvaluator());
        $this->assertTrue($converted->accepting_requests);
        $this->assertSame($converted->id, $activity->fresh()->user_id);
        $this->assertSame($converted->id, $message->fresh()->recipient_id);
        $this->assertTrue($conversation->fresh()->includesUser($converted));
        $this->assertSame($original, $existing->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('evaluator_verifications', 3);
        $this->assertSame(0, User::where('email', 'like', '%@evaluators.analista.test')->count());
        $this->assertCount(3, $seeder->credentials());

        foreach ($seeder->credentials() as $credential) {
            $this->assertTrue(Auth::validate(['email' => $credential['email'], 'password' => $credential['password']]));
        }
    }

    public function test_rerunning_does_not_duplicate_reset_passwords_or_reverse_moderation(): void
    {
        User::factory()->admin()->create();
        $seeder = new AdditionalEvaluatorSeeder;
        $seeder->run();
        $credential = $seeder->credentials()[0];
        $evaluator = User::where('email', $credential['email'])->sole();
        $evaluator->update(['name' => 'Updated account name', 'evaluator_status' => 'rejected', 'is_suspended' => true]);
        $original = $evaluator->refresh()->getAttributes();
        $seeder->run();

        $this->assertDatabaseCount('users', 4);
        $this->assertSame($original, $evaluator->fresh()->getAttributes());
        $this->assertSame([], $seeder->credentials());
        $this->assertTrue(Hash::check($credential['password'], $evaluator->fresh()->password));
    }

    public function test_destination_collision_rolls_back_all_conversions_and_exposes_no_credentials(): void
    {
        User::factory()->admin()->create();
        $first = $this->legacyEvaluator('adrian.mendoza', 'Adrian Mendoza');
        $second = $this->legacyEvaluator('camille.villareal', 'Camille Villareal');
        $collision = User::factory()->create(['email' => 'camille.villareal@analista.test']);
        $originals = [$first->refresh()->getAttributes(), $second->refresh()->getAttributes(), $collision->refresh()->getAttributes()];
        $seeder = new AdditionalEvaluatorSeeder;

        try {
            $seeder->run();
            $this->fail('A conflicting destination must stop the entire conversion.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('destination login', $exception->getMessage());
        }

        $this->assertSame($originals[0], $first->fresh()->getAttributes());
        $this->assertSame($originals[1], $second->fresh()->getAttributes());
        $this->assertSame($originals[2], $collision->fresh()->getAttributes());
        $this->assertSame([], $seeder->credentials());
        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseMissing('users', ['email' => 'adrian.mendoza@analista.test']);
    }

    public function test_legacy_email_alone_cannot_authorize_overwriting_an_unrelated_account(): void
    {
        User::factory()->admin()->create();
        $existing = User::factory()->create(['email' => 'adrian.mendoza@evaluators.analista.test']);
        $original = $existing->refresh()->getAttributes();
        $seeder = new AdditionalEvaluatorSeeder;

        try {
            $seeder->run();
            $this->fail('An unrelated account must not be promoted or overwritten.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('untouched legacy evaluator seed', $exception->getMessage());
        }

        $this->assertSame($original, $existing->fresh()->getAttributes());
        $this->assertSame([], $seeder->credentials());
        $this->assertDatabaseCount('users', 2);
    }

    public function test_changed_legacy_profile_cannot_be_overwritten(): void
    {
        User::factory()->admin()->create();
        $existing = $this->legacyEvaluator('adrian.mendoza', 'Adrian Mendoza');
        $existing->update(['bio' => 'This person replaced the original seeded biography.']);
        $original = $existing->refresh()->getAttributes();

        try {
            (new AdditionalEvaluatorSeeder)->run();
            $this->fail('A changed legacy account must be preserved.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('untouched legacy evaluator seed', $exception->getMessage());
        }

        $this->assertSame($original, $existing->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 2);
    }

    public function test_regular_email_owned_by_another_role_is_not_promoted(): void
    {
        User::factory()->admin()->create();
        $existing = User::factory()->create(['email' => 'adrian.mendoza@analista.test']);
        $original = $existing->refresh()->getAttributes();

        try {
            (new AdditionalEvaluatorSeeder)->run();
            $this->fail('An existing student account must not be promoted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('already belongs to another account', $exception->getMessage());
        }

        $this->assertSame($original, $existing->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 2);
    }

    public function test_production_seeding_is_refused_before_creating_any_users(): void
    {
        $previous = $this->app->environment();
        try {
            $this->app->instance('env', 'production');
            $this->app->make(AdditionalEvaluatorSeeder::class)->run();
            $this->fail('Production must not receive local evaluator accounts.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('local or testing', $exception->getMessage());
        } finally {
            $this->app->instance('env', $previous);
        }
        $this->assertDatabaseCount('users', 0);
    }

    private function legacyEvaluator(string $localPart, string $name): User
    {
        return User::factory()->evaluator()->create([
            'email' => $localPart.'@evaluators.analista.test',
            'name' => $name,
            'email_verified_at' => null,
            'experience_years' => 0,
            'headline' => 'Sample evaluator - project reviews',
            'bio' => 'Sample evaluator profile for local development. This account previews the evaluator directory, messages, and review workflow. Its listed topics and skills are examples; no professional credentials or supporting evidence have been verified.',
            'moderation_note' => 'Auto-approved sample account created at the project owner\'s request. No verification evidence was submitted or reviewed.',
        ]);
    }
}
