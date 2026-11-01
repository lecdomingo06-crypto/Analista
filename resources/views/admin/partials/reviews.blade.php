<header class="admin-section-heading"><h2>Review monitoring</h2><p>Follow evaluator progress and agreed completion dates. Overdue indicators start the day after the agreed date; assignments and review workflows stay unchanged.</p></header>
<div class="admin-review-summary" aria-label="Totals across all reviews">
    <a href="{{ route('admin.index', ['section' => 'reviews', 'status' => 'ongoing']) }}"><strong>{{ $stats['ongoing_reviews'] }}</strong> Ongoing</a>
    <a href="{{ route('admin.index', ['section' => 'reviews', 'status' => 'completed']) }}"><strong>{{ $stats['completed_reviews'] }}</strong> Completed</a>
    <a href="{{ route('admin.index', ['section' => 'reviews', 'status' => 'overdue']) }}"><strong>{{ $stats['overdue_reviews'] }}</strong> Overdue</a>
</div>
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
                    <th scope="row" data-label="Project"><strong>{{ $review->project?->title ?? 'Project unavailable' }}</strong><p class="hint">@if($review->document)PDF version {{ $review->document->version }} · @endif Review #{{ $review->id }}</p></th>
                    <td data-label="Evaluator">{{ $review->evaluator?->name ?? 'Evaluator unavailable' }}</td>
                    <td data-label="Status"><span class="status status-{{ $monitorStatus }}"><span></span>{{ $monitorLabels[$monitorStatus] ?? Str::headline($monitorStatus) }}</span></td>
                    <td data-label="Agreed completion">@if($review->agreed_date)<time datetime="{{ $review->agreed_date->toDateString() }}">{{ $review->agreed_date->format('M j, Y') }}</time>@else<span class="hint">Not agreed</span>@endif @if($daysOverdue)<p class="admin-review-overdue">{{ $daysOverdue }} {{ Str::plural('day', $daysOverdue) }} overdue</p>@endif</td>
                    <td data-label="Action"><a class="btn btn-primary btn-small" href="{{ route('reviews.show', $review) }}" aria-label="View review #{{ $review->id }} for {{ $review->project?->title ?? 'this project' }}">View review <x-icon name="arrow-up-right"/></a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@else
    <x-empty title="No matching reviews." text="Try another project or evaluator name, or reset your filters." />
@endif
<x-pagination :paginator="$reviews" />
