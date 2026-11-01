<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HomeEvaluatorSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_initially_selects_three_distinct_available_evaluators_with_random_database_ordering(): void
    {
        $eligible = User::factory()->evaluator()->count(8)->create();

        DB::connection()->enableQueryLog();
        try {
            $response = $this->get(route('home'))->assertOk();
            $queries = DB::connection()->getQueryLog();
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }

        $selected = $response->viewData('reviewers');
        $this->assertCount(3, $selected);
        $this->assertCount(3, $selected->pluck('id')->unique());
        $this->assertEmpty(array_diff($selected->modelKeys(), $eligible->modelKeys()));
        $response->assertSessionHas('home.featured_evaluator_ids', $selected->modelKeys());

        // Verify the actual database order rather than relying on chance across requests.
        $selectionQuery = collect($queries)->first(fn (array $query) =>
            str_contains(strtolower($query['query']), 'from "users"')
            && str_contains(strtolower($query['query']), 'limit 3')
        );
        $this->assertNotNull($selectionQuery);
        $this->assertStringContainsString('order by random()', strtolower($selectionQuery['query']));
        $this->assertStringNotContainsString('created_at', strtolower($selectionQuery['query']));
    }

    public function test_home_keeps_the_selected_evaluators_in_the_same_order_for_the_session(): void
    {
        User::factory()->evaluator()->count(8)->create();
        $selectedIds = $this->get(route('home'))->assertOk()->viewData('reviewers')->modelKeys();

        // Growing the pool must not replace an existing, still eligible session selection.
        User::factory()->evaluator()->count(5)->create();
        DB::connection()->enableQueryLog();
        try {
            foreach (range(1, 3) as $visit) {
                $response = $this->get(route('home'))->assertOk()
                    ->assertSessionHas('home.featured_evaluator_ids', $selectedIds);
                $this->assertSame($selectedIds, $response->viewData('reviewers')->modelKeys());
            }
            $queries = DB::connection()->getQueryLog();
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }

        $this->assertFalse(collect($queries)->contains(fn (array $query) =>
            str_contains(strtolower($query['query']), 'order by random()')
        ));
    }

    public function test_home_draws_a_new_random_selection_after_the_saved_session_selection_is_cleared(): void
    {
        User::factory()->evaluator()->count(8)->create();
        $this->get(route('home'))->assertOk()->assertSessionHas('home.featured_evaluator_ids');
        $this->app['session.store']->flush();

        DB::connection()->enableQueryLog();
        try {
            $response = $this->get(route('home'))->assertOk();
            $queries = DB::connection()->getQueryLog();
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }

        $this->assertCount(3, $response->viewData('reviewers'));
        $response->assertSessionHas('home.featured_evaluator_ids', $response->viewData('reviewers')->modelKeys());
        $this->assertTrue(collect($queries)->contains(fn (array $query) =>
            str_contains(strtolower($query['query']), 'order by random()')
        ));
    }

    public function test_home_revalidates_saved_evaluators_and_only_fills_the_vacancies(): void
    {
        $retained = User::factory()->evaluator()->create();
        $unavailable = User::factory()->evaluator()->create(['accepting_requests' => false]);
        $unapproved = User::factory()->pendingEvaluator()->create();
        $replacements = User::factory()->evaluator()->count(4)->create();

        $response = $this->withSession(['home.featured_evaluator_ids' => [$unavailable->id, $retained->id, $unapproved->id]])
            ->get(route('home'))->assertOk();
        $selected = $response->viewData('reviewers');

        $this->assertCount(3, $selected);
        $this->assertSame($retained->id, $selected->first()->id);
        $this->assertEmpty(array_diff($selected->slice(1)->modelKeys(), $replacements->modelKeys()));
        $this->assertNotContains($unavailable->id, $selected->modelKeys());
        $this->assertNotContains($unapproved->id, $selected->modelKeys());
        $response->assertSessionHas('home.featured_evaluator_ids', $selected->modelKeys());
    }

    #[DataProvider('malformedSelections')]
    public function test_home_safely_replaces_malformed_session_selection(mixed $stored): void
    {
        $eligible = User::factory()->evaluator()->count(4)->create();
        $response = $this->withSession(['home.featured_evaluator_ids' => $stored])
            ->get(route('home'))->assertOk();
        $selectedIds = $response->viewData('reviewers')->modelKeys();

        $this->assertCount(3, $selectedIds);
        $this->assertCount(3, array_unique($selectedIds));
        $this->assertEmpty(array_diff($selectedIds, $eligible->modelKeys()));
        $response->assertSessionHas('home.featured_evaluator_ids', $selectedIds);
    }

    public static function malformedSelections(): array
    {
        return [[null], ['broken'], [[[1], false, -1, 0, '1', 1.5]]];
    }

    public function test_home_limits_saved_selection_to_three_unique_ids_and_preserves_their_order(): void
    {
        $eligible = User::factory()->evaluator()->count(5)->create();
        $expected = [$eligible[3]->id, $eligible[1]->id, $eligible[0]->id];
        $stored = [$eligible[3]->id, $eligible[3]->id, $eligible[1]->id, $eligible[0]->id, $eligible[4]->id];

        $response = $this->withSession(['home.featured_evaluator_ids' => $stored])
            ->get(route('home'))->assertOk();

        $this->assertSame($expected, $response->viewData('reviewers')->modelKeys());
        $response->assertSessionHas('home.featured_evaluator_ids', $expected);
    }

    public function test_home_never_features_pending_rejected_suspended_unavailable_or_non_evaluator_accounts(): void
    {
        $eligible = User::factory()->evaluator()->create(['name' => 'Eligible volunteer']);
        $excluded = collect([
            User::factory()->pendingEvaluator()->create(['name' => 'Pending applicant']),
            User::factory()->evaluator()->create(['name' => 'Rejected applicant', 'evaluator_status' => 'rejected']),
            User::factory()->evaluator()->create(['name' => 'Suspended volunteer', 'is_suspended' => true]),
            User::factory()->evaluator()->create(['name' => 'Unavailable volunteer', 'accepting_requests' => false]),
            User::factory()->create(['name' => 'Student account', 'evaluator_status' => 'approved']),
            User::factory()->admin()->create(['name' => 'Administrator account', 'evaluator_status' => 'approved']),
        ]);

        $response = $this->get(route('home'))->assertOk()
            ->assertViewHas('reviewers', fn ($reviewers) => $reviewers->count() === 1 && $reviewers->first()->is($eligible))
            ->assertSee($eligible->name);

        foreach ($excluded as $user) {
            $response->assertDontSee($user->name);
        }
    }

    #[DataProvider('smallEvaluatorPools')]
    public function test_home_handles_fewer_than_three_eligible_evaluators_without_filling_with_other_accounts(int $count): void
    {
        $eligible = User::factory()->evaluator()->count($count)->create();
        User::factory()->pendingEvaluator()->count(3)->create();

        $response = $this->get(route('home'))->assertOk();
        $selected = $response->viewData('reviewers');

        $this->assertCount($count, $selected);
        $this->assertEqualsCanonicalizing($eligible->modelKeys(), $selected->modelKeys());
        foreach ($eligible as $user) {
            $response->assertSee($user->name);
        }
    }

    public static function smallEvaluatorPools(): array
    {
        return [[0], [1], [2]];
    }
}
