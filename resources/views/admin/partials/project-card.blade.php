@php
    $technologies = $project->technologies ?? [];
    $visibleTechnologies = array_slice($technologies, 0, count($technologies) > 4 ? 3 : 4);
    $remainingTechnologies = array_slice($technologies, count($visibleTechnologies));
    $projectUrl = route('admin.projects.show', ['project' => $project, 'q' => $filters['q'], 'status' => $filters['status'], 'projects_page' => $projects->currentPage()]);
@endphp
<article class="panel stack admin-project-card" id="project-{{ $project->id }}" aria-labelledby="project-title-{{ $project->id }}">
    <h3 class="admin-project-title" id="project-title-{{ $project->id }}">{{ $project->title }}</h3>
    <p class="admin-project-card-description">{{ \Illuminate\Support\Str::limit($project->summary, 120) }}</p>
    <div class="admin-project-badges"><x-status :status="$project->status" />@if($project->category)<span class="badge">{{ $project->category }}</span>@endif<span class="badge">{{ $project->is_public ? 'Public summary requested' : 'Private project' }}</span></div>
    @if($technologies)<div class="tags admin-project-technologies" aria-label="Project technologies">@foreach($visibleTechnologies as $technology)<span class="tag">{{ $technology }}</span>@endforeach @if($remainingTechnologies)<span class="tag" title="{{ implode(', ', $remainingTechnologies) }}" aria-label="{{ count($remainingTechnologies) }} more technologies: {{ implode(', ', $remainingTechnologies) }}">+{{ count($remainingTechnologies) }}</span>@endif</div>@endif
    <div class="admin-project-card-actions">
        <a class="btn btn-small" href="{{ $projectUrl }}">Inspect project</a>
        <a class="btn btn-primary btn-small" href="{{ $projectUrl }}#project-moderation">Moderate</a>
    </div>
</article>
