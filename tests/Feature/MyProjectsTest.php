<?php

namespace Tests\Feature;

use App\Models\DocumentVersion;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\Rating;
use App\Models\ReviewRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyProjectsTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_projects_requires_authentication_and_includes_every_owned_status_and_group_project_only(): void
    {
        $this->get(route('account.projects'))->assertRedirect(route('login'));
        $user = User::factory()->create();
        $ids = [];
        foreach (['draft', 'pending', 'approved', 'needs_changes', 'rejected', 'hidden'] as $status) {
            $ids[] = Project::factory()->create(['user_id' => $user->id, 'status' => $status, 'is_public' => false])->id;
        }
        $group = Project::factory()->create(['title' => 'PRIVATE GROUP PROJECT']);
        $group->members()->attach($user);
        $ids[] = $group->id;
        $private = Project::factory()->create(['title' => 'UNRELATED PRIVATE PROJECT']);
        $public = Project::factory()->publicListing()->create(['title' => 'UNRELATED PUBLIC PROJECT']);

        $this->actingAs($user)->get(route('account.projects'))->assertOk()
            ->assertViewHas('projects', fn ($items) => $items->total() === 7 && $items->getCollection()->pluck('id')->sort()->values()->all() === $ids)
            ->assertSee($group->title)->assertDontSee($private->title)->assertDontSee($public->title);
    }

    public function test_student_projects_are_scoped_to_owned_or_group_projects(): void
    {
        $user = User::factory()->create();
        $owned = Project::factory()->create(['user_id' => $user->id]);
        $group = Project::factory()->create();
        $group->members()->attach($user);
        $unrelated = Project::factory()->publicListing()->create();

        $this->actingAs($user)->get(route('account.projects'))->assertOk()
            ->assertViewHas('projects', fn ($items) => $items->total() === 2 && $items->getCollection()->pluck('id')->sort()->values()->all() === [$owned->id, $group->id])
            ->assertSee($owned->title)->assertSee($group->title)->assertDontSee($unrelated->title)
            ->assertSee('href="'.route('projects.create').'"', false);
    }

    public function test_admin_personal_project_and_review_urls_redirect_to_administration(): void
    {
        $admin = User::factory()->admin()->create();
        $owned = Project::factory()->create(['user_id' => $admin->id]);
        $this->actingAs($admin);
        $this->get(route('account.projects'))->assertRedirect(route('admin.index', ['section' => 'projects']));
        $this->get(route('account.projects', ['q' => 'old saved search', 'status' => 'draft']))
            ->assertRedirect(route('admin.index', ['section' => 'projects']));
        $this->get(route('account.dashboard', ['tab' => 'projects']))
            ->assertRedirect(route('admin.index', ['section' => 'projects']));
        $this->get(route('account.dashboard'))->assertRedirect(route('admin.index'));
        $this->get(route('account.dashboard', ['tab' => 'reviews']))
            ->assertRedirect(route('admin.index', ['section' => 'reviews']));
        $this->get(route('account.reviews'))->assertRedirect(route('admin.index', ['section' => 'reviews']));
        $this->assertDatabaseHas('projects', ['id' => $owned->id, 'user_id' => $admin->id]);
    }

    public function test_admin_navigation_keeps_admin_tools_without_my_projects(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        foreach ([route('home'), route('admin.index'), route('account.profile'),
            route('account.dashboard', ['tab' => 'bookmarks']),
            route('account.dashboard', ['tab' => 'notifications'])] as $url) {
            $response = $this->get($url)->assertOk();
            $xpath = $this->xpath($response->getContent());
            $this->assertSame(0, $xpath->query('//a[@href="'.route('account.projects').'"]')->length);
            $this->assertSame(1, $xpath->query('//aside[@id="sidebar"]//a[@href="'.route('admin.index').'"]')->length);
            foreach ([route('messages.index'), route('account.profile'), route('account.dashboard', ['tab' => 'notifications'])] as $destination) {
                $this->assertSame(1, $xpath->query('//aside[@id="sidebar"]/nav//a[@href="'.$destination.'"]')->length);
            }
        }
    }

    public function test_search_status_filters_and_pagination_keep_the_personal_project_scope(): void
    {
        $user = User::factory()->create();
        Project::factory()->count(13)->approved()->create(['user_id' => $user->id, 'school' => 'Focused Academy']);
        Project::factory()->count(2)->create(['user_id' => $user->id, 'school' => 'Focused Academy']);
        Project::factory()->approved()->create(['user_id' => $user->id, 'school' => 'Another school']);
        $other = Project::factory()->approved()->create(['school' => 'Focused Academy', 'title' => 'OUTSIDE FILTER MATCH']);
        $titleMatch = Project::factory()->create(['user_id' => $user->id, 'title' => 'Unique searchable research title']);

        $response = $this->actingAs($user)->get(route('account.projects', ['q' => 'Focused Academy', 'status' => 'approved']))
            ->assertOk()->assertViewHas('projects', fn ($items) => $items->total() === 13 && $items->count() === 12)
            ->assertDontSee($other->title);
        $next = $response->viewData('projects')->nextPageUrl();
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        $this->assertSame('Focused Academy', $query['q']);
        $this->assertSame('approved', $query['status']);
        $this->assertSame('2', $query['page']);
        $this->get($next)->assertOk()->assertViewHas('projects', fn ($items) => $items->total() === 13 && $items->count() === 1);
        $this->get(route('account.projects', ['q' => 'Unique searchable']))->assertOk()
            ->assertViewHas('projects', fn ($items) => $items->total() === 1 && $items->first()->is($titleMatch));
    }

    public function test_explicit_legacy_projects_tabs_redirect_to_the_personal_page_preserving_filters(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('account.dashboard', [
            'tab' => 'projects', 'q' => 'Research', 'status' => 'draft', 'page' => 2,
        ]))->assertRedirect(route('account.projects', ['q' => 'Research', 'status' => 'draft', 'page' => 2]));
        $this->get(route('account.dashboard'))->assertRedirect(route('account.projects'));
    }

    public function test_my_projects_has_an_active_sidebar_link_and_delete_is_an_explicit_owner_only_form(): void
    {
        $user = User::factory()->create();
        $owned = Project::factory()->create(['user_id' => $user->id]);
        DocumentVersion::factory()->create(['project_id' => $owned->id]);
        $group = Project::factory()->create();
        $group->members()->attach($user);
        $response = $this->actingAs($user)->get(route('account.projects'))->assertOk()
            ->assertSee('href="'.route('account.projects.show', $owned).'"', false)
            ->assertSee('href="'.route('projects.edit', $owned).'"', false)
            ->assertSee('href="'.route('projects.edit', $group).'"', false);
        $xpath = $this->xpath($response->getContent());
        $links = $xpath->query('//aside[@id="sidebar"]//a[@href="'.route('account.projects').'"]');
        $this->assertSame(1, $links->length);
        $this->assertStringContainsString('active', $links->item(0)->getAttribute('class'));
        $this->assertSame('page', $links->item(0)->getAttribute('aria-current'));
        $forms = $xpath->query('//form[@action="'.route('projects.destroy', $owned).'"]');
        $this->assertSame(1, $forms->length);
        $confirmation = $xpath->query('.//input[@name="delete_confirmation"]', $forms->item(0));
        $this->assertSame(1, $confirmation->length);
        $this->assertSame('', $confirmation->item(0)->getAttribute('value'));
        $this->assertTrue($confirmation->item(0)->hasAttribute('required'));
        $this->assertSame(0, $xpath->query('//form[@action="'.route('projects.destroy', $group).'"]')->length);
    }

    public function test_a_group_member_can_still_edit_existing_project_details(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $project = Project::factory()->approved()->create(['user_id' => $owner->id]);
        $project->members()->attach($member);
        DocumentVersion::factory()->create(['project_id' => $project->id]);
        $this->actingAs($member)->get(route('projects.edit', $project))->assertOk();
        $this->put(route('projects.update', $project), [
            'title' => 'Revised thesis details by an authorized group member', 'summary' => $project->summary,
            'school' => $project->school, 'course' => $project->course, 'category' => $project->category,
            'technologies' => implode(', ', $project->technologies), 'feedback_needed' => $project->feedback_needed,
            'is_public' => false, 'member_emails' => $member->email,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Revised thesis details by an authorized group member', $project->fresh()->title);
        $this->assertSame($owner->id, $project->fresh()->user_id);
        $this->assertSame('approved', $project->fresh()->status);
    }

    public function test_a_project_without_reviews_has_no_fabricated_review_link(): void
    {
        $project = Project::factory()->approved()->create();
        $response = $this->actingAs($project->owner)->get(route('account.projects'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $card = $xpath->query('//article[@id="my-project-'.$project->id.'"]')->item(0);

        $this->assertSame(1, $xpath->query('.//a[@href="'.route('account.projects.show', $project).'" and contains(normalize-space(.),"View project")]', $card)->length);
        $this->assertSame(1, $xpath->query('.//a[@href="'.route('projects.edit', $project).'"]', $card)->length);
        $this->assertSame(0, $xpath->query('.//a[contains(@href,"/reviews/")]', $card)->length);
        $this->assertSame(1, $xpath->query('.//*[@aria-disabled="true" and contains(normalize-space(.),"View review") and not(@href)]', $card)->length);
    }

    public function test_a_single_review_action_opens_the_project_evaluator_chooser_for_every_request_status(): void
    {
        $student = User::factory()->create();
        $projects = [];
        foreach (['pending', 'accepted', 'completed', 'declined'] as $status) {
            $project = Project::factory()->approved()->create(['user_id' => $student->id]);
            $review = ReviewRequest::factory()->create(['project_id' => $project->id, 'status' => $status,
                'final_summary' => $status === 'completed' ? 'Private single-review findings should remain on the review detail screen.' : null]);
            $projects[] = [$project, $review];
        }

        $response = $this->actingAs($student)->get(route('account.projects'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        foreach ($projects as [$project, $review]) {
            $card = $xpath->query('//article[@id="my-project-'.$project->id.'"]')->item(0);
            $destination = route('account.projects.reviews', $project);
            $this->assertSame(1, $xpath->query('.//a[@href="'.$destination.'" and contains(normalize-space(.),"View review")]', $card)->length);
            $this->assertSame(0, $xpath->query('.//details[.//a[contains(@href,"/reviews/")]]', $card)->length);
            $chooser = $this->get($destination)->assertOk();
            $chooser->assertSee(route('reviews.show', $review).($review->status === 'completed' ? '#review-summary' : ''), false);
            $this->get(route('reviews.show', $review))->assertOk();
            if ($review->final_summary) {
                $response->assertDontSee($review->final_summary);
            }
        }
    }

    public function test_multiple_reviews_offer_a_project_evaluator_chooser_without_inline_feedback(): void
    {
        $project = Project::factory()->approved()->create();
        $member = User::factory()->create();
        $project->members()->attach($member);
        $reviews = collect();
        foreach (['pending', 'accepted', 'completed', 'declined'] as $status) {
            $reviews->push(ReviewRequest::factory()->create(['project_id' => $project->id, 'status' => $status,
                'final_summary' => $status === 'completed' ? 'Private completed findings are accessible through their review link only.' : null]));
        }
        $completed = $reviews->firstWhere('status', 'completed');
        $feedback = Feedback::factory()->create(['review_request_id' => $completed->id,
            'body' => 'Private methodology notes must not be expanded on the compact project card.']);
        $unrelated = ReviewRequest::factory()->completed()->create(['final_summary' => 'Another team secret findings.']);

        foreach ([$project->owner, $member] as $viewer) {
            $response = $this->actingAs($viewer)->get(route('account.projects'))->assertOk()
                ->assertDontSee($completed->final_summary)->assertDontSee($feedback->body)
                ->assertDontSee($unrelated->project->title)->assertDontSee($unrelated->final_summary);
            $xpath = $this->xpath($response->getContent());
            $card = $xpath->query('//article[@id="my-project-'.$project->id.'"]')->item(0);
            $chooserLink = $xpath->query('.//a[@href="'.route('account.projects.reviews', $project).'" and contains(normalize-space(.),"View review")]', $card);
            $this->assertSame(1, $chooserLink->length);
            $this->assertSame(0, $xpath->query('.//details[summary[contains(normalize-space(.),"View review")]]', $card)->length);
            $chooser = $this->get(route('account.projects.reviews', $project))->assertOk()
                ->assertDontSee($completed->final_summary)->assertDontSee($feedback->body);
            $chooserXpath = $this->xpath($chooser->getContent());
            foreach ($reviews as $review) {
                $destination = route('reviews.show', $review).($review->status === 'completed' ? '#review-summary' : '');
                $links = $chooserXpath->query('//a[@href="'.$destination.'"]');
                $this->assertSame(1, $links->length);
                $chooser->assertSee($review->evaluator->name);
                $this->get(route('reviews.show', $review))->assertOk();
            }
        }

        $project->members()->detach($member);
        foreach ([$member, User::factory()->create()] as $outsider) {
            $this->actingAs($outsider)->get(route('account.projects'))->assertOk()
                ->assertDontSee($project->title)->assertDontSee($completed->final_summary)->assertDontSee($feedback->body);
            $this->get(route('reviews.show', $completed))->assertForbidden();
        }
    }

    public function test_the_legacy_project_list_keeps_its_private_summary_and_personal_rating_action(): void
    {
        $project = Project::factory()->approved()->create();
        $review = ReviewRequest::factory()->completed()->create(['project_id' => $project->id,
            'final_summary' => 'Legacy dashboard summary remains available without changing its existing rich project layout.']);
        Rating::factory()->create(['review_request_id' => $review->id, 'user_id' => $project->user_id, 'rating' => 4]);
        $this->actingAs($project->owner)->get(route('account.dashboard', ['tab' => 'notifications']))->assertOk();
        $this->assertFalse(request()->routeIs('account.projects'));
        $project->load(['owner', 'reviewRequests.evaluator', 'reviewRequests.document', 'reviewRequests.ratings'])->loadCount('documents');
        $html = view('account.partials.project-list', ['projects' => collect([$project]), 'user' => $project->owner])->render();

        $this->assertStringContainsString($review->final_summary, $html);
        $this->assertStringContainsString('Edit rating', $html);
        $this->assertStringContainsString(route('reviews.show', $review).'#rating', $html);
        $this->assertStringContainsString(route('reviews.show', $review).'#review-summary', $html);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);

        return new \DOMXPath($document);
    }
}
