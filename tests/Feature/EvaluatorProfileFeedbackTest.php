<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Rating;
use App\Models\ReviewRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvaluatorProfileFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_feedback_distribution_uses_all_of_this_evaluators_ratings_on_every_page(): void
    {
        $evaluator = User::factory()->evaluator()->create();
        $otherEvaluator = User::factory()->evaluator()->create();
        foreach ([5, 5, 5, 4, 3, 2, 1, 1] as $stars) {
            $this->rate($evaluator, $stars);
        }
        foreach ([1, 1, 1] as $stars) {
            $this->rate($otherEvaluator, $stars);
        }

        foreach ([1 => 6, 2 => 2] as $page => $visibleCount) {
            $response = $this->get(route('evaluators.show', ['evaluator' => $evaluator, 'page' => $page]))
                ->assertOk()
                ->assertViewHas('ratingDistribution', [5 => 3, 4 => 1, 3 => 1, 2 => 1, 1 => 2])
                ->assertViewHas('evaluator', fn ($profile) => $profile->ratings_received_count === 8
                    && (float) $profile->ratings_received_avg_rating === 3.25);

            $ratings = $response->viewData('ratings');
            $this->assertSame(8, $ratings->total());
            $this->assertSame(6, $ratings->perPage());
            $this->assertCount($visibleCount, $ratings->items());
            $this->assertTrue($ratings->every(fn ($rating) => $rating->evaluator_id === $evaluator->id));
            $this->assertTrue($ratings->every(fn ($rating) => $rating->relationLoaded('author')));
        }
    }

    public function test_an_unrated_profile_has_zero_distribution_and_does_not_invent_feedback(): void
    {
        $evaluator = User::factory()->evaluator()->create();
        $this->rate(User::factory()->evaluator()->create(), 5);

        $this->get(route('evaluators.show', $evaluator))->assertOk()
            ->assertViewHas('ratingDistribution', [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0])
            ->assertViewHas('evaluator', fn ($profile) => $profile->ratings_received_count === 0
                && $profile->ratings_received_avg_rating === null)
            ->assertViewHas('ratings', fn ($ratings) => $ratings->isEmpty() && $ratings->total() === 0)
            ->assertSee('No ratings yet')
            ->assertDontSee('Rated a completed thesis review.');
    }

    public function test_feedback_is_escaped_and_review_contact_and_report_forms_keep_their_bindings(): void
    {
        $student = User::factory()->create();
        $evaluator = User::factory()->evaluator()->create();
        $owned = Project::factory()->approved()->create(['user_id' => $student->id]);
        $group = Project::factory()->approved()->create();
        $group->members()->attach($student);
        $completed = Project::factory()->create(['user_id' => $student->id, 'status' => 'completed']);
        $unrelated = Project::factory()->approved()->create();
        $comment = '<script>window.untrustedFeedback = true;</script> Keep the full recommendation.';
        $this->rate($evaluator, 4)->update(['comment' => $comment]);

        $response = $this->actingAs($student)->get(route('evaluators.show', $evaluator))->assertOk()
            ->assertSee($comment)->assertDontSee($comment, false)
            ->assertViewHas('projects', fn ($projects) => $projects->count() === 2
                && $projects->contains('id', $owned->id) && $projects->contains('id', $group->id))
            ->assertDontSee($completed->title)->assertDontSee($unrelated->title);
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $reviewForm = '//form[@action="'.route('reviews.prepare').'" and @method="GET"]';
        $reportForm = '//form[@action="'.route('reports.store').'" and @method="post"]';

        $this->assertSame(1, $xpath->query($reviewForm.'/input[@name="evaluator_id" and @value="'.$evaluator->id.'"]')->length);
        $this->assertSame(2, $xpath->query($reviewForm.'//select[@name="project_id" and @required]/option')->length);
        $this->assertSame(1, $xpath->query('//a[@href="'.route('messages.compose', $evaluator).'"]')->length);
        $this->assertSame(1, $xpath->query($reportForm.'/input[@name="reportable_id" and @value="'.$evaluator->id.'"]')->length);
        $this->assertSame(1, $xpath->query($reportForm.'/input[@name="reportable_type" and @value="user"]')->length);
        $this->assertSame(1, $xpath->query($reportForm.'/input[@name="_token"]')->length);
    }

    private function rate(User $evaluator, int $stars): Rating
    {
        $review = ReviewRequest::factory()->completed()->create(['evaluator_id' => $evaluator->id]);

        return Rating::factory()->create(['review_request_id' => $review->id, 'rating' => $stars]);
    }
}
