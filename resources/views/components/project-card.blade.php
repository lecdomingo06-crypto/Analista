@props(['project'])
@php
    $reviewCount = (int) $project->review_requests_count;
    $projectCapsules = [];
    if ($project->status === 'completed') {
        $projectCapsules[] = ['label' => 'Completed', 'class' => 'thesis-project-tag-status', 'icon' => 'check-check'];
    }
    if ($project->category) {
        $projectCapsules[] = ['label' => $project->category, 'class' => 'thesis-project-tag-category'];
    }
    foreach ($project->technologies ?? [] as $technology) {
        $projectCapsules[] = ['label' => $technology, 'class' => ''];
    }
    $visibleCapsules = count($projectCapsules) > 4 ? array_slice($projectCapsules, 0, 3) : $projectCapsules;
    $hiddenCapsules = array_slice($projectCapsules, count($visibleCapsules));
    $hiddenCapsuleLabels = implode(', ', array_column($hiddenCapsules, 'label'));
@endphp
<article class="project-card thesis-project-card" aria-labelledby="project-title-{{ $project->id }}">
    <h2 class="thesis-project-title" id="project-title-{{ $project->id }}">
        <a href="{{ route('projects.show', $project) }}" title="{{ $project->title }}">{{ $project->title }}</a>
    </h2>
    <p class="thesis-project-affiliation">
        <span title="{{ $project->course }}">{{ $project->course }}</span>
        <span title="{{ $project->school }}">{{ $project->school }}</span>
    </p>
    <p class="thesis-project-summary">{{ $project->summary }}</p>
    <div class="thesis-project-tags" aria-label="Project status, category and technologies">
        @foreach($visibleCapsules as $capsule)
            <span class="thesis-project-tag {{ $capsule['class'] }}">@if(isset($capsule['icon']))<x-icon :name="$capsule['icon']"/> @endif{{ $capsule['label'] }}</span>
        @endforeach
        @if($hiddenCapsules)
            <span class="thesis-project-tag thesis-project-tag-more" title="{{ $hiddenCapsuleLabels }}" aria-label="{{ count($hiddenCapsules) }} more tags: {{ $hiddenCapsuleLabels }}">+{{ count($hiddenCapsules) }}</span>
        @endif
    </div>
    <div class="thesis-project-stats">
        <span><x-icon name="message-circle"/> {{ $reviewCount }} {{ Str::plural('review request', $reviewCount) }}</span>
        <span><x-icon name="clock"/> @if($project->deadline)Preferred <time datetime="{{ $project->deadline->toDateString() }}">{{ $project->deadline->format('M j, Y') }}</time>@else Flexible timeline @endif</span>
    </div>
    @include('projects.partials.review-progress')
    <footer class="thesis-project-footer">
        <div class="thesis-project-creator">
            <x-avatar :user="$project->owner"/>
            <span class="thesis-project-creator-name" title="{{ $project->owner?->name }}">{{ $project->owner?->name }}</span>
        </div>
        <a class="thesis-project-link" href="{{ route('projects.show', $project) }}" aria-label="View project: {{ $project->title }}">View project <x-icon name="arrow-up-right"/></a>
    </footer>
</article>
