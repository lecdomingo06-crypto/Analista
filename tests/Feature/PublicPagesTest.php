<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Tutorial;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
    }

    public function test_guests_can_open_the_public_pages_and_authentication_forms(): void
    {
        $evaluator = User::where('email', 'evaluator@analista.test')->sole();
        $tutorial = Tutorial::where('slug', 'develop-a-research-question')->sole();
        $project = Project::where('status', 'approved')->where('is_public', true)->firstOrFail();
        $urls = [
            route('home'), route('evaluators.index'), route('evaluators.show', $evaluator),
            route('projects.index'), route('projects.show', $project),
            route('tutorials.index'), route('tutorials.show', $tutorial), route('guidelines'), route('faq'),
            route('login'), route('register'), route('password.request'),
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }

        $this->get(route('evaluators.show', User::where('email', 'pending@analista.test')->sole()))->assertNotFound();
        $this->get(route('account.dashboard'))->assertRedirect(route('login'));
        $this->get(route('account.reviews'))->assertRedirect(route('login'));
        $this->get(route('account.profile'))->assertRedirect(route('login'));
        $this->get(route('admin.index'))->assertRedirect(route('login'));
    }

    public function test_reviewer_search_and_filters_return_relevant_approved_profiles_only(): void
    {
        $this->get(route('evaluators.index', ['q' => 'Sofia']))->assertOk()
            ->assertViewHas('evaluators', fn ($items) => $items->count() === 1 && $items->first()->email === 'evaluator@analista.test')
            ->assertSee('Sofia Reyes')->assertDontSee('Miguel Santos')->assertDontSee('Jamie Torres');

        $this->get(route('evaluators.index', ['category' => 'Security', 'experience' => 7, 'available' => 1, 'sort' => 'experience']))
            ->assertOk()->assertViewHas('evaluators', fn ($items) => $items->count() === 1 && $items->first()->name === 'Rafael Navarro');

        $this->get(route('evaluators.index', ['q' => 'no-matching-reviewer']))->assertOk()
            ->assertViewHas('evaluators', fn ($items) => $items->isEmpty());
    }

    public function test_tutorials_use_slug_links_and_support_search_and_topic_filters(): void
    {
        $tutorial = Tutorial::where('slug', 'research-methodology-in-four-steps')->sole();
        $this->assertSame(url('/tutorials/'.$tutorial->slug), route('tutorials.show', $tutorial));

        $this->get(route('tutorials.index'))->assertOk()
            ->assertSee('href="'.route('tutorials.show', $tutorial).'"', false);
        $this->get(route('tutorials.show', $tutorial))->assertOk()->assertSee($tutorial->title)
            ->assertSee($tutorial->video_creator)
            ->assertSee($tutorial->embed_url, false);
        $this->get(route('tutorials.index', ['q' => 'methodology', 'category' => 'Methodology']))
            ->assertOk()->assertViewHas('tutorials', fn ($items) => $items->count() === 1 && $items->first()->is($tutorial));

        $unpublished = Tutorial::factory()->create(['is_published' => false]);
        $this->get(route('tutorials.show', $unpublished))->assertNotFound();
    }

    public function test_students_can_open_review_requests_other_workspace_pages_and_project_forms(): void
    {
        $student = User::where('email', 'student@analista.test')->sole();
        $this->actingAs($student);

        foreach (['reviews', 'notifications', 'bookmarks'] as $tab) {
            $this->get(route('account.dashboard', ['tab' => $tab]))->assertOk()->assertViewHas('tab', $tab);
        }
        $this->get(route('account.reviews'))->assertOk()->assertViewIs('account.reviews')->assertSee('Review requests');
        $this->get(route('account.projects'))->assertOk()
            ->assertSee('href="'.route('account.reviews').'"', false);
        $this->get(route('account.dashboard', ['tab' => 'bookmarks']))->assertOk()
            ->assertSee('href="'.route('account.reviews').'"', false);

        $this->get(route('account.profile'))->assertOk()->assertSee($student->email);
        $this->get(route('projects.create'))->assertOk();
        $this->get(route('projects.edit', $student->projects()->firstOrFail()))->assertOk();
    }

    public function test_evaluators_can_open_their_workspace_basic_profile_and_professional_verification(): void
    {
        $evaluator = User::where('email', 'evaluator@analista.test')->sole();
        $this->actingAs($evaluator);

        foreach (['reviews', 'notifications', 'bookmarks'] as $tab) {
            $this->get(route('account.dashboard', ['tab' => $tab]))->assertOk()->assertViewHas('tab', $tab);
        }
        $this->get(route('account.projects'))->assertOk();

        $this->get(route('account.profile'))->assertOk()->assertSee($evaluator->email)
            ->assertDontSee('name="headline"', false)->assertDontSee('name="review_modes[]"', false);
        $this->get(route('account.evaluator-verification.show'))->assertOk()->assertSee($evaluator->headline)
            ->assertSee('value="online" checked', false);
        $this->assertSame(['online'], $evaluator->review_modes);
    }

    public function test_bookmarks_are_personal_toggle_correctly_and_hide_unpublished_articles(): void
    {
        $student = User::where('email', 'student@analista.test')->sole();
        $other = User::where('email', 'lia@analista.test')->sole();
        $saved = Tutorial::where('slug', 'develop-a-research-question')->sole();
        $othersSaved = Tutorial::where('slug', 'apa-7-in-text-citations')->sole();
        $unpublished = Tutorial::factory()->create(['is_published' => false]);
        $other->bookmarks()->attach($othersSaved);
        $student->bookmarks()->attach($unpublished);

        $this->actingAs($student)->post(route('account.bookmarks.toggle', $saved))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($saved->bookmarkedBy()->whereKey($student->id)->exists());
        $this->get(route('tutorials.index', ['saved' => 1]))->assertOk()
            ->assertViewHas('tutorials', fn ($items) => $items->count() === 1 && $items->first()->is($saved));
        $this->get(route('account.dashboard', ['tab' => 'bookmarks']))->assertOk()
            ->assertSee($saved->title)->assertDontSee($othersSaved->title)->assertDontSee($unpublished->title);

        $this->post(route('account.bookmarks.toggle', $unpublished))->assertNotFound();
        $this->post(route('account.bookmarks.toggle', $saved))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertFalse($student->bookmarks()->whereKey($saved->id)->exists());
        $this->assertTrue($other->bookmarks()->whereKey($othersSaved->id)->exists());
    }

    public function test_student_registration_creates_a_student_and_does_not_accept_privileged_fields(): void
    {
        $this->post(route('register'), [
            'name' => 'New Student', 'email' => 'new-student@example.test',
            'password' => 'NewStudent!2026', 'password_confirmation' => 'NewStudent!2026',
            'role' => 'student', 'terms' => '1', 'evaluator_status' => 'approved',
        ])->assertSessionHasNoErrors()->assertRedirect(route('account.dashboard'));

        $user = User::where('email', 'new-student@example.test')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('student', $user->role);
        $this->assertSame('none', $user->evaluator_status);
        $this->assertFalse($user->isAdmin());
    }

    public function test_evaluator_registration_waits_for_admin_approval_and_admin_registration_is_rejected(): void
    {
        $data = ['name' => 'New Reviewer', 'email' => 'new-reviewer@example.test',
            'password' => 'NewReviewer!2026', 'password_confirmation' => 'NewReviewer!2026', 'terms' => '1'];
        $this->postJson(route('register'), $data + ['role' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->post(route('register'), $data + ['role' => 'evaluator', 'evaluator_status' => 'approved'])
            ->assertSessionHasNoErrors()->assertRedirect(route('account.evaluator-verification.show'));

        $user = User::where('email', $data['email'])->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('evaluator', $user->role);
        $this->assertSame('pending', $user->evaluator_status);
        $this->assertFalse($user->accepting_requests);
        $this->assertFalse($user->isEvaluator());
        $this->get(route('admin.index'))->assertForbidden();
    }

    public function test_demo_student_can_log_in_and_log_out_and_admin_is_redirected_to_admin_area(): void
    {
        $student = User::where('email', 'student@analista.test')->sole();
        $this->post(route('login'), ['email' => $student->email, 'password' => 'AnalistaDemo!2026'])
            ->assertSessionHasNoErrors()->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticatedAs($student);
        $this->post(route('logout'))->assertRedirect(route('home'));
        $this->assertGuest();

        $this->post(route('login'), ['email' => 'admin@analista.test', 'password' => 'AnalistaDemo!2026'])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.index'));
        $this->assertAuthenticatedAs(User::where('email', 'admin@analista.test')->sole());
    }

    public function test_suspended_accounts_and_incorrect_passwords_cannot_log_in(): void
    {
        $student = User::where('email', 'student@analista.test')->sole();
        $student->update(['is_suspended' => true]);

        $this->post(route('login'), ['email' => $student->email, 'password' => 'AnalistaDemo!2026'])
            ->assertSessionHasErrors('email')->assertRedirect();
        $this->assertGuest();

        $this->post(route('login'), ['email' => 'evaluator@analista.test', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email')->assertRedirect();
        $this->assertGuest();
    }
}
