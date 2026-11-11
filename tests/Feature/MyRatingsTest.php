<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Rating;
use App\Models\ReviewRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyRatingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_only_evaluator_accounts_can_open_received_ratings(): void
    {
        $this->get(route('account.ratings'))->assertRedirect(route('login'));

        foreach ([User::factory()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get(route('account.ratings'))->assertForbidden();
        }

        foreach ([User::factory()->evaluator()->create(), User::factory()->pendingEvaluator()->create()] as $evaluator) {
            $rating = $this->rate($evaluator, 4);
            $this->actingAs($evaluator)->get(route('account.ratings'))->assertOk()
                ->assertViewHas('user', fn ($user) => $user->is($evaluator))
                ->assertViewHas('ratings', fn ($ratings) => $ratings->total() === 1 && $ratings->first()->is($rating));
        }
    }

    public function test_suspended_evaluators_cannot_open_received_ratings(): void
    {
        $evaluator = User::factory()->evaluator()->create(['is_suspended' => true]);

        $this->actingAs($evaluator)->get(route('account.ratings'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_received_ratings_are_scoped_to_the_signed_in_evaluator_with_student_and_project_context(): void
    {
        $evaluator = User::factory()->evaluator()->create();
        $author = User::factory()->create(['name' => 'Student who rated my review']);
        $project = Project::factory()->create(['user_id' => $author->id, 'title' => 'Private campus scheduling research', 'is_public' => false]);
        $review = ReviewRequest::factory()->completed()->create(['project_id' => $project->id, 'evaluator_id' => $evaluator->id]);
        $own = Rating::factory()->create(['review_request_id' => $review->id, 'rating' => 4, 'comment' => 'Useful feedback for our scheduling study.']);
        $other = $this->rate(User::factory()->evaluator()->create(), 1, ['comment' => 'Another evaluator confidential rating.']);

        $response = $this->actingAs($evaluator)->get(route('account.ratings', ['evaluator_id' => $other->evaluator_id]))
            ->assertOk()->assertSee($author->name)->assertSee($project->title)->assertSee($own->comment)
            ->assertDontSee($other->comment)->assertDontSee($other->reviewRequest->project->title)
            ->assertViewHas('summary', ['total' => 1, 'average' => 4.0])
            ->assertViewHas('ratingDistribution', [5 => 0, 4 => 1, 3 => 0, 2 => 0, 1 => 0]);

        $ratings = $response->viewData('ratings');
        $this->assertSame([$own->id], $ratings->getCollection()->modelKeys());
        $this->assertTrue($ratings->first()->relationLoaded('author'));
        $this->assertTrue($ratings->first()->relationLoaded('reviewRequest'));
        $this->assertTrue($ratings->first()->reviewRequest->relationLoaded('project'));
        $this->assertSame(1, $this->xpath($response->getContent())->query('//a[@href="'.route('reviews.show', $review).'"]')->length);
    }

    public function test_summary_and_distribution_include_all_received_ratings_when_filtering_and_paginating(): void
    {
        $evaluator = User::factory()->evaluator()->create();
        foreach ([...array_fill(0, 13, 5), 4, 2, 1] as $stars) {
            $this->rate($evaluator, $stars);
        }
        $this->rate(User::factory()->evaluator()->create(), 1);
        $this->actingAs($evaluator);

        foreach ([1 => 12, 2 => 1] as $page => $visibleCount) {
            $response = $this->get(route('account.ratings', ['stars' => 5, 'page' => $page]))->assertOk()
                ->assertViewHas('summary', ['total' => 16, 'average' => 4.5])
                ->assertViewHas('ratingDistribution', [5 => 13, 4 => 1, 3 => 0, 2 => 1, 1 => 1]);
            $ratings = $response->viewData('ratings');
            $this->assertSame(13, $ratings->total());
            $this->assertSame(12, $ratings->perPage());
            $this->assertCount($visibleCount, $ratings->items());
            $this->assertTrue($ratings->every(fn ($rating) => $rating->rating === 5 && $rating->evaluator_id === $evaluator->id));
            $this->assertSame('5', (string) $response->viewData('selectedRating'));

            if ($page === 1) {
                parse_str(parse_url($ratings->nextPageUrl(), PHP_URL_QUERY), $query);
                $this->assertSame('5', $query['stars']);
                $this->assertSame('2', $query['page']);
            }
        }

        $this->get(route('account.ratings'))->assertOk()
            ->assertViewHas('ratings', fn ($ratings) => $ratings->total() === 16 && $ratings->count() === 12)
            ->assertViewHas('selectedRating', '');
    }

    public function test_newest_feedback_is_first_with_a_deterministic_order_for_equal_dates(): void
    {
        $evaluator = User::factory()->evaluator()->create();
        $old = $this->rate($evaluator, 1, ['created_at' => now()->subDays(2)]);
        $firstAtSameTime = $this->rate($evaluator, 4, ['created_at' => now()->subDay()]);
        $secondAtSameTime = $this->rate($evaluator, 5, ['created_at' => $firstAtSameTime->created_at]);
        $newest = $this->rate($evaluator, 3, ['created_at' => now()]);

        $this->actingAs($evaluator)->get(route('account.ratings'))->assertOk()
            ->assertViewHas('ratings', fn ($ratings) => $ratings->getCollection()->modelKeys() === [
                $newest->id, $secondAtSameTime->id, $firstAtSameTime->id, $old->id,
            ]);
    }

    public function test_empty_and_filtered_empty_states_do_not_invent_ratings(): void
    {
        $evaluator = User::factory()->evaluator()->create();
        $other = $this->rate(User::factory()->evaluator()->create(), 5, ['comment' => 'Unrelated feedback must stay private.']);

        $this->actingAs($evaluator)->get(route('account.ratings'))->assertOk()
            ->assertSee('No ratings yet')->assertDontSee($other->comment)
            ->assertViewHas('summary', ['total' => 0, 'average' => null])
            ->assertViewHas('ratingDistribution', [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0])
            ->assertViewHas('ratings', fn ($ratings) => $ratings->isEmpty());

        $own = $this->rate($evaluator, 4);
        $this->get(route('account.ratings', ['stars' => 1]))->assertOk()
            ->assertSee('No ratings match this filter')->assertDontSee($own->comment)
            ->assertViewHas('summary', ['total' => 1, 'average' => 4.0])
            ->assertViewHas('ratings', fn ($ratings) => $ratings->isEmpty() && $ratings->total() === 0);
    }

    public function test_the_star_filter_rejects_invalid_values_and_accepts_an_empty_filter(): void
    {
        $this->actingAs(User::factory()->evaluator()->create());
        foreach ([0, 6, -1, '1.5', 'all', ['5']] as $stars) {
            $this->getJson(route('account.ratings', ['stars' => $stars]))
                ->assertUnprocessable()->assertJsonValidationErrors('stars');
        }

        $this->get(route('account.ratings', ['stars' => '']))->assertOk()
            ->assertViewHas('selectedRating', '');
    }

    public function test_rating_comments_student_names_and_project_titles_are_escaped(): void
    {
        $evaluator = User::factory()->evaluator()->create();
        $student = User::factory()->create(['name' => '<img src=x onerror="window.untrustedName=true">']);
        $project = Project::factory()->create(['user_id' => $student->id, 'title' => '<svg onload="window.untrustedProject=true">Research</svg>']);
        $review = ReviewRequest::factory()->completed()->create(['project_id' => $project->id, 'evaluator_id' => $evaluator->id]);
        $comment = '<script>window.untrustedFeedback=true</script> Our review was useful.';
        Rating::factory()->create(['review_request_id' => $review->id, 'comment' => $comment]);

        $this->actingAs($evaluator)->get(route('account.ratings'))->assertOk()
            ->assertSee($student->name)->assertDontSee($student->name, false)
            ->assertSee($project->title)->assertDontSee($project->title, false)
            ->assertSee($comment)->assertDontSee($comment, false);
    }

    public function test_legacy_evaluator_projects_urls_redirect_without_stale_filters_and_preserve_flash_messages(): void
    {
        $evaluator = User::factory()->evaluator()->create();
        $this->actingAs($evaluator);

        foreach ([route('account.projects', ['q' => 'Old search', 'status' => 'draft', 'page' => 99]),
            route('account.dashboard', ['tab' => 'projects', 'q' => 'Old search', 'status' => 'invalid', 'page' => 99]),
            route('account.dashboard', ['tab' => 'ratings', 'q' => 'Old search'])] as $url) {
            $this->withSession(['success' => 'Your account update was saved.', '_flash' => ['old' => ['success'], 'new' => []]])
                ->get($url)->assertRedirect(route('account.ratings'));
            $this->get(route('account.ratings'))->assertOk()->assertSee('Your account update was saved.');
            $this->get(route('account.ratings'))->assertOk()->assertDontSee('Your account update was saved.');
        }

        $this->get(route('account.dashboard'))->assertRedirect(route('account.reviews'));
    }

    public function test_the_evaluator_workspace_menu_replaces_my_projects_with_my_ratings_and_the_sidebar_stays_compact(): void
    {
        $evaluator = User::factory()->evaluator()->create();
        $response = $this->actingAs($evaluator)->get(route('account.ratings'))->assertOk()->assertSee('My ratings');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//nav[contains(@class,"workspace-menu-links")]//a[@href="'.route('account.ratings').'" and @aria-current="page"]')->length);
        $this->assertSame(0, $xpath->query('//nav[contains(@class,"workspace-menu-links")]//a[@href="'.route('account.projects').'"]')->length);
        $this->assertSame(0, $xpath->query('//aside[@id="sidebar"]//a[@href="'.route('account.ratings').'"]')->length);
        $this->assertSame(1, $xpath->query('//aside[@id="sidebar"]//a[@href="'.route('account.reviews').'"]')->length);

        $response = $this->actingAs(User::factory()->create())->get(route('account.projects'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//nav[contains(@class,"workspace-menu-links")]//a[@href="'.route('account.projects').'"]')->length);
        $this->assertSame(0, $xpath->query('//nav[contains(@class,"workspace-menu-links")]//a[@href="'.route('account.ratings').'"]')->length);
    }

    private function rate(User $evaluator, int $stars, array $attributes = []): Rating
    {
        $review = ReviewRequest::factory()->completed()->create(['evaluator_id' => $evaluator->id]);

        return Rating::factory()->create(['review_request_id' => $review->id, 'rating' => $stars, ...$attributes]);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new \DOMXPath($document);
    }
}
