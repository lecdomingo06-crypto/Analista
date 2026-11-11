<?php

namespace Tests\Feature;

use App\Models\DirectMessage;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\Rating;
use App\Models\ReviewRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReviewNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_legacy_account_redirect_preserves_the_registration_success_message(): void
    {
        $this->actingAs(User::factory()->create())->withSession([
            'success' => 'Welcome to your thesis projects.',
            '_flash' => ['old' => ['success'], 'new' => []],
        ])->get(route('account.dashboard'))->assertRedirect(route('account.projects'));

        $this->get(route('account.projects'))->assertOk()->assertSee('Welcome to your thesis projects.');
        $this->get(route('account.projects'))->assertOk()->assertDontSee('Welcome to your thesis projects.');
    }

    public function test_account_landing_and_sidebar_use_role_specific_project_and_review_navigation(): void
    {
        foreach ([User::factory()->create(), User::factory()->evaluator()->create(), User::factory()->admin()->create()] as $user) {
            $this->actingAs($user)->get(route('account.dashboard'))
                ->assertRedirect(route(match ($user->role) {
                    'admin' => 'admin.index',
                    'evaluator' => 'account.reviews',
                    default => 'account.projects',
                }));
            $response = $this->get(route('guidelines'))->assertOk()->assertDontSee('My workspace');
            $xpath = $this->xpath($response->getContent());
            $this->assertSame(0, $xpath->query('//aside[@id="sidebar"]/nav//a[@href="'.route('account.dashboard').'"]')->length);
            $this->assertSame($user->role === 'student' ? 1 : 0,
                $xpath->query('//aside[@id="sidebar"]/nav//a[@href="'.route('account.projects').'"]')->length);
            $this->assertSame($user->role === 'evaluator' ? 1 : 0,
                $xpath->query('//aside[@id="sidebar"]/nav//a[@href="'.route('account.reviews').'"]')->length);
            $this->get(route('account.dashboard', ['tab' => 'projects', 'q' => 'campus']))
                ->assertRedirect(match ($user->role) {
                    'admin' => route('admin.index', ['section' => 'projects']),
                    'evaluator' => route('account.ratings'),
                    default => route('account.projects', ['q' => 'campus']),
                });
        }
    }

    public function test_review_request_urls_redirect_admins_without_forwarding_review_filters(): void
    {
        $admin = User::factory()->admin()->create();
        foreach (['account.reviews', 'account.dashboard'] as $route) {
            $query = ['status' => 'accepted', 'origin' => 'saved-review-link'];
            if ($route === 'account.dashboard') {
                $query['tab'] = 'reviews';
            }
            $this->actingAs($admin)->withSession([
                'success' => 'Your review update was saved.',
                '_flash' => ['old' => ['success'], 'new' => []],
            ])->get(route($route, $query))
                ->assertRedirect(route('admin.index', ['section' => 'reviews']));

            $destination = $this->get(route('admin.index', ['section' => 'reviews']))
                ->assertOk()->assertSessionHasNoErrors()->assertSee('Your review update was saved.')
                ->assertViewHas('filters', fn ($filters) => $filters['status'] === '');
            $xpath = $this->xpath($destination->getContent());
            $this->assertSame(0, $xpath->query('//a[@href="'.route('account.reviews').'" or contains(@href,"tab=reviews")]')->length);
            $this->get(route('admin.index', ['section' => 'reviews']))->assertOk()->assertDontSee('Your review update was saved.');
        }

        $this->get(route('account.dashboard', ['tab' => 'bookmarks']))->assertOk()
            ->assertDontSee('href="'.route('account.reviews').'"', false)
            ->assertDontSee('tab=reviews');
    }

    public function test_evaluator_queue_filters_status_keeps_counts_and_orders_oldest_pending_first(): void
    {
        $evaluator = User::factory()->evaluator()->create();
        $older = $this->reviewFor($evaluator, 'Older pending parking project', 'pending', now()->subDays(3));
        $newer = $this->reviewFor($evaluator, 'Newer pending library project', 'pending', now()->subDay());
        $active = $this->reviewFor($evaluator, 'Active research review', 'accepted', now()->subDays(5));
        $completed = $this->reviewFor($evaluator, 'Completed networking review', 'completed', now());
        $unrelated = ReviewRequest::factory()->create();
        $owned = Project::factory()->approved()->create(['user_id' => $evaluator->id, 'title' => 'Evaluator-owned thesis reviewed by someone else']);
        ReviewRequest::factory()->create(['project_id' => $owned->id]);
        $group = Project::factory()->approved()->create(['title' => 'Evaluator group thesis reviewed by someone else']);
        $group->members()->attach($evaluator);
        ReviewRequest::factory()->accepted()->create(['project_id' => $group->id]);

        $response = $this->actingAs($evaluator)->get(route('account.reviews'))->assertOk()
            ->assertSeeInOrder([$older->project->title, $newer->project->title])
            ->assertSee($active->project->title)->assertSee($completed->project->title)
            ->assertDontSee($unrelated->project->title)
            ->assertDontSee($owned->title)->assertDontSee($group->title)
            ->assertDontSee('Reviews for your projects')
            ->assertViewHas('stats', fn ($stats) => $stats['pending_reviews'] === 2 && $stats['active_reviews'] === 1 && $stats['completed_reviews'] === 1);
        $this->assertSame([$older->id, $newer->id, $completed->id, $active->id], $response->viewData('assignedReviews')->modelKeys());
        $xpath = $this->xpath($response->getContent());
        $statValues = $xpath->query('//div[contains(@class,"stats-grid")]/div/span[contains(@class,"mono")]');
        $this->assertSame(['2', '1', '1'], array_map(fn ($node) => trim($node->textContent), iterator_to_array($statValues)));
        $this->get(route('account.reviews', ['status' => 'pending']))->assertOk()
            ->assertSee($older->project->title)->assertSee($newer->project->title)
            ->assertDontSee($active->project->title)->assertDontSee($completed->project->title)
            ->assertViewHas('stats', fn ($stats) => $stats['pending_reviews'] === 2 && $stats['active_reviews'] === 1 && $stats['completed_reviews'] === 1);
        $this->get(route('account.reviews', ['status' => 'completed']))->assertOk()
            ->assertSee($completed->project->title)->assertDontSee($older->project->title);
    }

    public function test_students_can_follow_owned_and_group_reviews_from_requests_and_my_projects(): void
    {
        $student = User::factory()->create();
        $owned = Project::factory()->approved()->create(['user_id' => $student->id, 'title' => 'Owned thesis review request']);
        $group = Project::factory()->approved()->create(['title' => 'Joined group thesis request']);
        $group->members()->attach($student);
        $ownedReview = ReviewRequest::factory()->create(['project_id' => $owned->id]);
        $groupReview = ReviewRequest::factory()->accepted()->create(['project_id' => $group->id]);
        $completed = ReviewRequest::factory()->completed()->create(['project_id' => $owned->id,
            'final_summary' => 'Private evaluation details remain on the completed review page.',
            'output_original_name' => 'private-review-annotations.pdf']);
        $completed->update(['output_path' => 'review-outputs/'.$completed->id.'/'.str_repeat('b', 40).'.pdf']);
        $unrelated = ReviewRequest::factory()->completed()->create(['requested_by' => $student->id]);

        $queue = $this->actingAs($student)->get(route('account.reviews'))->assertOk()->assertViewIs('account.reviews')
            ->assertSee('Review requests')->assertSee('Reviews for your projects')->assertDontSee('Assigned to you')
            ->assertSee($owned->title)->assertSee($group->title)->assertDontSee($unrelated->project->title)
            ->assertSee($ownedReview->evaluator->name)->assertSee($groupReview->evaluator->name)
            ->assertDontSee($completed->final_summary)->assertDontSee($completed->output_original_name)
            ->assertDontSee(route('reviews.output', $completed))
            ->assertViewHas('stats', fn ($stats) => $stats['pending_requests'] === 1 && $stats['active_requests'] === 1 && $stats['completed_requests'] === 1);
        $this->assertEqualsCanonicalizing([$ownedReview->id, $groupReview->id, $completed->id], $queue->viewData('reviewRequests')->modelKeys());
        $queueXpath = $this->xpath($queue->getContent());
        $this->assertSame(3, $queueXpath->query('//article[contains(@class,"account-review-card")]')->length);
        foreach ([$ownedReview, $groupReview, $completed] as $review) {
            $this->assertSame(1, $queueXpath->query('//article[@aria-labelledby="project-review-'.$review->id.'"]//a[@href="'.route('reviews.show', $review).'"]')->length);
        }
        $statValues = $queueXpath->query('//div[contains(@class,"stats-grid")]/div/span[contains(@class,"mono")]');
        $this->assertSame(['1', '1', '1'], array_map(fn ($node) => trim($node->textContent), iterator_to_array($statValues)));
        $this->get(route('account.reviews', ['status' => 'accepted']))->assertOk()
            ->assertViewHas('reviewRequests', fn ($items) => $items->modelKeys() === [$groupReview->id])
            ->assertViewHas('stats', fn ($stats) => $stats['pending_requests'] === 1 && $stats['active_requests'] === 1 && $stats['completed_requests'] === 1)
            ->assertDontSee($owned->title)->assertDontSee($unrelated->project->title);
        $this->get(route('account.dashboard', ['tab' => 'reviews', 'status' => 'completed']))->assertOk()
            ->assertViewIs('account.reviews')->assertViewHas('tab', 'reviews')
            ->assertViewHas('reviewRequests', fn ($items) => $items->modelKeys() === [$completed->id]);

        $response = $this->get(route('account.projects'))->assertOk()
            ->assertSee($owned->title)->assertSee($group->title)->assertDontSee($unrelated->project->title)
            ->assertSee('1 review request')->assertSee('View review')
            ->assertSee('href="'.route('account.reviews').'"', false);
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//article[@id="my-project-'.$owned->id.'"]//a[@href="'.route('account.projects.reviews', $owned).'"]')->length);
        $this->assertSame(1, $xpath->query('//article[@id="my-project-'.$group->id.'"]//a[@href="'.route('account.projects.reviews', $group).'"]')->length);
        $this->assertSame(0, $xpath->query('//a[@href="'.route('reviews.show', $unrelated).'#review-summary"]')->length);
    }

    public function test_my_projects_links_to_private_review_details_and_preserves_each_students_rating_form(): void
    {
        $student = User::factory()->create();
        $member = User::factory()->create();
        $project = Project::factory()->approved()->create(['user_id' => $student->id, 'title' => 'Parking thesis progress']);
        $project->members()->attach($member);
        $pending = ReviewRequest::factory()->create(['project_id' => $project->id]);
        $accepted = ReviewRequest::factory()->accepted()->create(['project_id' => $project->id]);
        $completed = ReviewRequest::factory()->completed()->create(['project_id' => $project->id,
            'final_summary' => 'Private parking evaluation summary: improve task coverage and document vehicle recognition failure cases.']);
        Rating::factory()->create(['review_request_id' => $completed->id, 'user_id' => $student->id, 'rating' => 4]);
        $unrelated = ReviewRequest::factory()->completed()->create(['final_summary' => 'Unrelated secret assessment that belongs only to another thesis team.']);

        $response = $this->actingAs($student)->get(route('account.projects'))->assertOk()
            ->assertSee('Ready for review')
            ->assertDontSee($completed->final_summary)->assertDontSee('Edit rating')->assertDontSee('Rate evaluator')
            ->assertDontSee($unrelated->final_summary)
            ->assertDontSee($unrelated->project->title);
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(0, $xpath->query('//article[@id="my-project-'.$project->id.'"]//a[@href="'.route('reviews.show', $completed).'#rating"]')->length);
        $this->assertSame(1, $xpath->query('//article[@id="my-project-'.$project->id.'"]//a[@href="'.route('account.projects.reviews', $project).'"]')->length);
        $this->get(route('account.projects.reviews', $project))->assertOk()
            ->assertSee($pending->evaluator->name)->assertSee($accepted->evaluator->name)->assertSee($completed->evaluator->name)
            ->assertSee('In review')->assertSee('Completed')
            ->assertDontSee($completed->final_summary)->assertDontSee($unrelated->final_summary);
        $ownerReview = $this->get(route('reviews.show', $completed))->assertOk()
            ->assertSee($completed->final_summary)->assertSee('Update rating')->assertDontSee($unrelated->final_summary);
        $this->assertSame(1, $this->xpath($ownerReview->getContent())->query('//section[@id="rating"]//form[@action="'.route('reviews.rate', $completed).'"]//input[@name="rating" and @value="4" and @checked]')->length);

        $this->actingAs($member)->get(route('account.projects'))->assertOk()->assertDontSee($completed->final_summary)
            ->assertSee('href="'.route('account.projects.reviews', $project).'"', false)
            ->assertDontSee('Rate evaluator')->assertDontSee('Edit rating');
        $memberReview = $this->get(route('reviews.show', $completed))->assertOk()
            ->assertSee($completed->final_summary)->assertSee('Submit rating')->assertDontSee('Update rating');
        $this->assertSame(1, $this->xpath($memberReview->getContent())->query('//section[@id="rating"]//form[@action="'.route('reviews.rate', $completed).'"]')->length);
        $this->assertSame(0, $this->xpath($memberReview->getContent())->query('//section[@id="rating"]//input[@name="rating" and @checked]')->length);
        $this->actingAs(User::factory()->create())->get(route('account.projects'))->assertOk()
            ->assertDontSee($project->title)->assertDontSee($completed->final_summary);
        $this->get(route('reviews.show', $completed))->assertForbidden();
    }

    public function test_removed_group_member_cannot_discover_private_review_details_in_requests_or_notifications(): void
    {
        $formerMember = User::factory()->create();
        $project = Project::factory()->approved()->create(['title' => 'Private former-team research project']);
        $project->members()->attach($formerMember);
        $review = ReviewRequest::factory()->completed()->create(['project_id' => $project->id,
            'requested_by' => $formerMember->id, 'final_summary' => 'Confidential final recommendations for the remaining research team.']);
        $project->members()->detach($formerMember);

        $this->actingAs($formerMember)->get(route('account.reviews'))->assertOk()
            ->assertDontSee($project->title)->assertDontSee($review->final_summary)
            ->assertViewHas('reviewRequests', fn ($items) => $items->isEmpty());
        $this->get(route('account.projects'))->assertOk()
            ->assertDontSee($project->title)->assertDontSee($review->final_summary)
            ->assertDontSee('href="'.route('reviews.show', $review).'#review-summary"', false)
            ->assertViewHas('projects', fn ($items) => $items->isEmpty());
        $this->get(route('account.dashboard', ['tab' => 'notifications']))->assertOk()
            ->assertDontSee($project->title)->assertDontSee($review->final_summary);
        $this->get(route('account.projects'))->assertOk()->assertDontSee($project->title);
        $this->get(route('reviews.show', $review))->assertForbidden();
    }

    public function test_public_project_detail_never_discloses_completed_summary_or_annotated_pdf_link(): void
    {
        $project = Project::factory()->publicListing()->create();
        $review = ReviewRequest::factory()->completed()->create(['project_id' => $project->id,
            'final_summary' => 'Private reviewer findings: participant identities and assessment evidence must remain restricted.',
            'output_original_name' => 'confidential-annotations.pdf']);
        $review->update(['output_path' => 'review-outputs/'.$review->id.'/'.str_repeat('a', 40).'.pdf']);
        $url = route('reviews.output', $review);
        $this->get(route('projects.show', $project))->assertOk()->assertDontSee($review->final_summary)
            ->assertDontSee($url)->assertDontSee('confidential-annotations.pdf');
        $this->actingAs(User::factory()->create())->get(route('projects.show', $project))->assertOk()
            ->assertDontSee($review->final_summary)->assertDontSee($url);
        $this->actingAs($project->owner)->get(route('projects.show', $project))->assertOk()
            ->assertDontSee($review->final_summary)->assertDontSee($url);
        $this->get(route('account.projects.show', $project))->assertOk()
            ->assertDontSee($review->final_summary)->assertDontSee($url)
            ->assertDontSee($review->output_original_name)->assertSee(route('reviews.show', $review));
        $this->get(route('reviews.show', $review))->assertOk()
            ->assertSee($review->final_summary)->assertSee($review->output_original_name)->assertSee($url);
    }

    public function test_public_evaluator_profile_directory_and_home_render_real_rating_averages_and_counts(): void
    {
        $rated = User::factory()->evaluator()->create(['name' => 'Rated Evaluator']);
        $unrated = User::factory()->evaluator()->create(['name' => 'New Evaluator']);
        foreach ([4, 5] as $stars) {
            $review = ReviewRequest::factory()->completed()->create(['evaluator_id' => $rated->id]);
            Rating::factory()->create(['review_request_id' => $review->id, 'rating' => $stars]);
        }
        $profile = $this->get(route('evaluators.show', $rated))->assertOk()->assertSee('4.5')->assertSee('2 ratings');
        $this->assertSame(2, $profile->viewData('evaluator')->ratings_received_count);
        $this->assertEquals(4.5, $profile->viewData('evaluator')->ratings_received_avg_rating);
        $this->get(route('evaluators.show', $unrated))->assertOk()->assertSee('No ratings yet');
        $directory = $this->get(route('evaluators.index'))->assertOk()->assertSee('4.5')->assertSee('No ratings yet');
        $ratedDirectory = $directory->viewData('evaluators')->firstWhere('id', $rated->id);
        $this->assertEquals(4.5, $ratedDirectory->ratings_received_avg_rating);
        $this->assertSame(2, $ratedDirectory->ratings_received_count);
        $home = $this->withSession(['home.featured_evaluator_ids' => [$rated->id, $unrated->id]])
            ->get(route('home'))->assertOk()->assertSee('4.5')->assertSee('No ratings yet');
        $ratedHome = $home->viewData('reviewers')->firstWhere('id', $rated->id);
        $this->assertEquals(4.5, $ratedHome->ratings_received_avg_rating);
        $this->assertSame(2, $ratedHome->ratings_received_count);
    }

    public function test_creating_from_an_evaluator_preserves_selection_and_returns_to_the_new_projects_review_form(): void
    {
        Storage::fake('local');
        $student = User::factory()->create();
        $evaluator = User::factory()->evaluator()->create();
        $form = $this->actingAs($student)->get(route('projects.create', ['evaluator_id' => $evaluator->id]))->assertOk();
        $xpath = $this->xpath($form->getContent());
        $hidden = $xpath->query('//form//input[@type="hidden" and @name="evaluator_id"]');
        $this->assertSame(1, $hidden->length);
        $this->assertSame((string) $evaluator->id, $hidden->item(0)->getAttribute('value'));
        $this->post(route('projects.store'), $this->projectData('Evaluator-selected parking project') + ['evaluator_id' => $evaluator->id])
            ->assertSessionHasNoErrors()->assertRedirect(route('account.projects.show', [
                'project' => Project::where('title', 'Evaluator-selected parking project')->sole(), 'evaluator' => $evaluator->id,
            ]).'#request-review');
        $project = Project::where('title', 'Evaluator-selected parking project')->sole();
        $this->get(route('account.projects.show', ['project' => $project, 'evaluator' => $evaluator->id]))->assertOk()
            ->assertSee('value="'.$evaluator->id.'" selected', false);
        $defaultForm = $this->get(route('projects.create'))->assertOk();
        $this->assertSame(0, $this->xpath($defaultForm->getContent())->query('//input[@name="evaluator_id"]')->length);
        $this->post(route('projects.store'), $this->projectData('Ordinary project submission'))
            ->assertSessionHasNoErrors()->assertRedirect(route('account.projects.show', Project::where('title', 'Ordinary project submission')->sole()));
    }

    public function test_deletion_removes_only_tracked_project_files_and_makes_existing_review_cards_unavailable(): void
    {
        Storage::fake('local');
        $project = Project::factory()->approved()->create();
        $document = DocumentVersion::factory()->create(['project_id' => $project->id, 'path' => 'theses/'.$project->id.'/original.pdf']);
        Storage::disk('local')->put($document->path, $this->pdf());
        $review = ReviewRequest::factory()->accepted()->create(['project_id' => $project->id, 'document_version_id' => $document->id]);
        $this->actingAs($review->evaluator)->post(route('reviews.complete', $review), [
            'final_summary' => 'Improve the evaluation tasks and clarify participant selection before completing the final thesis revision.',
            'annotated_pdf' => UploadedFile::fake()->createWithContent('annotated.pdf', $this->pdf()),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $output = $review->fresh()->safeOutputPath();
        $untracked = 'review-outputs/'.$review->id.'/'.str_repeat('u', 40).'.pdf';
        Storage::disk('local')->put($untracked, $this->pdf());
        $other = Project::factory()->approved()->create();
        $otherDocument = DocumentVersion::factory()->create(['project_id' => $other->id, 'path' => 'theses/'.$other->id.'/keep-original.pdf']);
        $otherReview = ReviewRequest::factory()->completed()->create(['project_id' => $other->id, 'document_version_id' => $otherDocument->id]);
        $otherOutput = 'review-outputs/'.$otherReview->id.'/'.str_repeat('k', 40).'.pdf';
        $otherReview->update(['output_path' => $otherOutput]);
        Storage::disk('local')->put($otherDocument->path, $this->pdf());
        Storage::disk('local')->put($otherOutput, $this->pdf());
        $card = DirectMessage::where('review_request_id', $review->id)->where('review_event', 'completed')->sole();
        $this->assertTrue($card->reviewCardFor($project->owner)['available']);

        $this->actingAs($project->owner)->delete(route('projects.destroy', $project), ['delete_confirmation' => 'DELETE'])
            ->assertSessionHasNoErrors()->assertRedirect(route('account.projects'));
        Storage::disk('local')->assertMissing($document->path);
        Storage::disk('local')->assertMissing($output);
        Storage::disk('local')->assertExists($untracked);
        Storage::disk('local')->assertExists($otherDocument->path);
        Storage::disk('local')->assertExists($otherOutput);
        $this->assertDatabaseHas('projects', ['id' => $other->id]);
        $this->assertDatabaseHas('review_requests', ['id' => $otherReview->id]);
        $card = $card->fresh();
        $this->assertNull($card->review_request_id);
        $this->assertFalse($card->reviewCardFor($project->owner)['available']);
        $this->assertSame('Review update unavailable', $card->reviewCardFor($project->owner)['label']);
    }

    private function reviewFor(User $evaluator, string $title, string $status, $createdAt): ReviewRequest
    {
        $project = Project::factory()->approved()->create(['title' => $title]);

        return ReviewRequest::factory()->create(['project_id' => $project->id, 'evaluator_id' => $evaluator->id,
            'status' => $status, 'created_at' => $createdAt, 'accepted_at' => $status !== 'pending' ? $createdAt : null,
            'completed_at' => $status === 'completed' ? now() : null]);
    }

    private function projectData(string $title): array
    {
        return ['title' => $title, 'summary' => 'A campus parking management thesis evaluating plate recognition and real-time slot availability.',
            'school' => 'Campus University', 'course' => 'BS Information Technology', 'category' => 'Web',
            'feedback_needed' => 'Please review the study design and evaluation methodology.',
            'document' => UploadedFile::fake()->createWithContent('thesis.pdf', $this->pdf())];
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new \DOMXPath($document);
    }
}
