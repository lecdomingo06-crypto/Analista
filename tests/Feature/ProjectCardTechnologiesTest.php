<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectCardTechnologiesTest extends TestCase
{
    use RefreshDatabase;

    private string $ownedViews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->ownedViews = storage_path('framework/testing/project-card-technologies-'.bin2hex(random_bytes(8)));
        (new Filesystem)->ensureDirectoryExists($this->ownedViews);
        config(['view.compiled' => $this->ownedViews]);
        $this->app->forgetInstance('blade.compiler');
        $this->app['view.engine.resolver']->forget('blade');
    }

    protected function tearDown(): void
    {
        $path = $this->ownedViews ?? null;
        $testingRoot = realpath(storage_path('framework/testing'));
        try {
            parent::tearDown();
        } finally {
            $resolved = $path ? realpath($path) : false;
            if ($testingRoot !== false && $resolved !== false
                && str_starts_with(strtolower($resolved), strtolower(rtrim($testingRoot, '\\/').DIRECTORY_SEPARATOR))) {
                (new Filesystem)->deleteDirectory($resolved);
            }
        }
    }

    public function test_owner_technology_edits_and_clears_are_reflected_in_fresh_public_cards_and_project_details(): void
    {
        $project = Project::factory()->publicListing()->create(['technologies' => ['OldFramework', 'OldDatabase']]);
        $this->actingAs($project->owner);
        $technologies = ['Laravel', 'React', 'Python', 'PostgreSQL', 'Redis'];
        $this->put(route('projects.update', $project), $this->payload($project, implode(', ', $technologies)))
            ->assertSessionHasNoErrors()->assertRedirect(route('account.projects.show', $project));
        $this->assertSame($technologies, $project->fresh()->technologies);
        $this->assertSame('Web', $project->fresh()->category);

        $response = $this->get(route('projects.index'))->assertOk()
            ->assertDontSee('OldFramework')->assertDontSee('OldDatabase');
        $tags = $this->cardTags($response->getContent(), $project);
        $this->assertSame(['Web', 'Laravel', 'React', '+3'], $this->tagLabels($tags));
        $overflow = $tags->item(3);
        foreach (['Python', 'PostgreSQL', 'Redis'] as $hidden) {
            $this->assertStringContainsString($hidden, $overflow->getAttribute('title'));
            $this->assertStringContainsString($hidden, $overflow->getAttribute('aria-label'));
        }
        $details = $this->get(route('projects.show', $project))->assertOk()
            ->assertDontSee('OldFramework')->assertDontSee('OldDatabase');
        foreach ($technologies as $technology) {
            $details->assertSee($technology);
        }

        $this->put(route('projects.update', $project), $this->payload($project, ''))
            ->assertSessionHasNoErrors()->assertRedirect(route('account.projects.show', $project));
        $this->assertSame([], $project->fresh()->technologies);
        $this->assertSame('Web', $project->fresh()->category);
        $cleared = $this->get(route('projects.index'))->assertOk();
        $this->assertSame(['Web'], $this->tagLabels($this->cardTags($cleared->getContent(), $project)));
        $details = $this->get(route('projects.show', $project))->assertOk()->assertDontSee('Built with');
        foreach ([...$technologies, 'OldFramework', 'OldDatabase'] as $technology) {
            $cleared->assertDontSee($technology);
            $details->assertDontSee($technology);
        }
    }

    public function test_card_capsule_limit_counts_category_and_completed_status_and_explains_every_hidden_label(): void
    {
        $cases = [
            ['approved', 'Web', ['Alpha', 'Beta', 'Gamma'], ['Web', 'Alpha', 'Beta', 'Gamma'], []],
            ['approved', 'Web', ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'], ['Web', 'Alpha', 'Beta', '+3'], ['Gamma', 'Delta', 'Epsilon']],
            ['completed', 'Web', ['Alpha', 'Beta'], ['Completed', 'Web', 'Alpha', 'Beta'], []],
            ['completed', 'Web', ['Alpha', 'Beta', 'Gamma', 'Delta'], ['Completed', 'Web', 'Alpha', '+3'], ['Beta', 'Gamma', 'Delta']],
            ['approved', '', ['Alpha', 'Beta', 'Gamma', 'Delta'], ['Alpha', 'Beta', 'Gamma', 'Delta'], []],
            ['approved', '', [], [], []],
        ];
        $projects = [];
        foreach ($cases as [$status, $category, $technologies]) {
            $projects[] = Project::factory()->publicListing()->create(compact('status', 'category', 'technologies'));
        }
        $response = $this->get(route('projects.index'))->assertOk();
        foreach ($cases as $index => [, , , $labels, $hidden]) {
            $tags = $this->cardTags($response->getContent(), $projects[$index]);
            $this->assertSame($labels, $this->tagLabels($tags));
            $this->assertLessThanOrEqual(4, $tags->length);
            if ($hidden !== []) {
                $overflow = $tags->item($tags->length - 1);
                foreach ($hidden as $label) {
                    $this->assertStringContainsString($label, $overflow->getAttribute('title'));
                    $this->assertStringContainsString($label, $overflow->getAttribute('aria-label'));
                }
            }
        }
    }

    private function cardTags(string $html, Project $project): \DOMNodeList
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);

        return (new \DOMXPath($document))->query('//article[@aria-labelledby="project-title-'.$project->id.'"]//div[contains(concat(" ",normalize-space(@class)," ")," thesis-project-tags ")]/span');
    }

    private function tagLabels(\DOMNodeList $tags): array
    {
        return array_map(fn ($tag) => trim(preg_replace('/\s+/', ' ', $tag->textContent)), iterator_to_array($tags));
    }

    private function payload(Project $project, string $technologies): array
    {
        return [
            'title' => $project->title, 'summary' => $project->summary,
            'school' => $project->school, 'course' => $project->course,
            'category' => $project->category, 'technologies' => $technologies,
            'feedback_needed' => $project->feedback_needed,
        ];
    }
}
