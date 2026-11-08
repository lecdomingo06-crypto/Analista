<header class="admin-section-heading"><h2>Review monitoring</h2></header>
<nav class="admin-review-summary" aria-label="Review totals and status filters">
    @foreach([
        'ongoing' => ['label' => 'Ongoing', 'count' => $stats['ongoing_reviews'], 'icon' => 'inbox'],
        'completed' => ['label' => 'Completed', 'count' => $stats['completed_reviews'], 'icon' => 'check'],
        'overdue' => ['label' => 'Overdue', 'count' => $stats['overdue_reviews'], 'icon' => 'clock'],
    ] as $status => $metric)
        <a class="admin-review-stat admin-review-stat-{{ $status }}" href="{{ route('admin.index', ['section' => 'reviews', 'status' => $status]) }}" @if($filters['status'] === $status) aria-current="true" @endif>
            <span class="admin-review-stat-icon" aria-hidden="true"><x-icon :name="$metric['icon']" /></span>
            <span class="admin-review-stat-copy"><span class="admin-review-stat-label">{{ $metric['label'] }}</span><strong class="admin-review-stat-value">{{ $metric['count'] }}</strong></span>
        </a>
    @endforeach
</nav>
@include('admin.partials.filters')
@if($reviews->count())
    <div class="panel admin-review-monitoring">
        <table class="admin-review-table" role="table">
            <caption class="sr-only">Project and evaluator review progress, with agreed completion dates.</caption>
            <thead><tr><th scope="col">Project</th><th scope="col">Evaluator</th><th scope="col">Status</th><th scope="col">Agreed completion</th><th scope="col">Action</th></tr></thead>
            <tbody>
            @foreach($reviews as $review)
                @php
                    $monitorStatus = $review->isOverdue() ? 'overdue' : $review->status;
                    $monitorLabels = ['accepted' => 'Ongoing', 'overdue' => 'Overdue', 'completed' => 'Completed', 'pending' => 'Pending request', 'declined' => 'Declined'];
                    $daysOverdue = $review->daysOverdue();
                @endphp
                <tr data-review-id="{{ $review->id }}">
                    <th scope="row" data-label="Project"><strong class="admin-review-project-title">{{ $review->project?->title ?? 'Project unavailable' }}</strong></th>
                    <td data-label="Evaluator"><span class="admin-review-evaluator-name">{{ $review->evaluator?->name ?? 'Evaluator unavailable' }}</span></td>
                    <td data-label="Status"><span class="status status-{{ $monitorStatus }}"><span></span>{{ $monitorLabels[$monitorStatus] ?? Str::headline($monitorStatus) }}</span></td>
                    <td data-label="Agreed completion">@if($review->agreed_date)<time datetime="{{ $review->agreed_date->toDateString() }}">{{ $review->agreed_date->format('M j, Y') }}</time>@else<span class="hint">Not agreed</span>@endif @if($daysOverdue)<p class="admin-review-overdue">{{ $daysOverdue }} {{ Str::plural('day', $daysOverdue) }} overdue</p>@endif</td>
                    <td data-label="Action"><a class="btn btn-primary btn-small admin-review-open" href="{{ route('reviews.show', $review) }}" aria-label="View {{ $review->evaluator?->name ?? 'the evaluator' }}'s review for {{ $review->project?->title ?? 'this project' }}">View review <x-icon name="arrow-up-right"/></a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@else
    <x-empty title="No matching reviews." text="Try another project or evaluator name, or reset your filters." />
@endif
<x-pagination :paginator="$reviews" />
